<?php

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\AiCapability;
use App\Domain\Ai\AiFailure;
use App\Domain\Ai\AiImage;
use App\Domain\Ai\AiModel;
use App\Domain\Ai\AiPrice;
use App\Domain\Ai\AiProvider;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AiResult;
use App\Domain\Ai\AiSettings;
use App\Domain\Ai\AiSettingsSection;
use App\Domain\Ai\AiUsage;
use App\Domain\Settings\SecretStore;
use App\Domain\Settings\SettingsCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\RequestException;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Http;
use JsonException;
use Laravel\Ai\Ai;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsReader;
use Throwable;

/**
 * An AI service Teme asks through laravel/ai's stateless request alone:
 * its key from Settings › AI or the server, its shipped models and the one an
 * administrator adds, its section of Settings › AI and that section's check.
 * The service's errors become Teme's five failure types and are let go, so
 * its words never leave this class (I-314). Each service's class says only
 * what differs.
 */
abstract class LibraryProvider implements AiProvider, AiSettingsSection, SettingsCheck
{
    /** Room for a reasoning model to think within the answer's limit (I-1360). */
    public const int REASONING_ALLOWANCE = 4_096;

    /** A model's name as a service writes it: no spaces, at most 200 characters. */
    private const string MODEL_NAME = '/^[A-Za-z0-9~][A-Za-z0-9._:\/@~-]{0,199}$/';

    private const array ABILITIES = [
        'text' => 'Text',
        'images-in' => 'Reading images',
        'structured-output' => 'Structured answers',
        'embeddings' => 'Embeddings',
        'rerank' => 'Reranking',
        'images-out' => 'Making images',
        'decide' => 'Decisions',
    ];

    public function __construct(
        private readonly SecretStore $secrets,
        private readonly SettingsReader $values,
    ) {}

    /** The laravel/ai driver that speaks to the service. */
    abstract protected function driver(): string;

    /** A free address that needs the key, for Check key. */
    abstract protected function checkUrl(): string;

    /**
     * The headers that carry the key, for Check key.
     *
     * @return array<string, string>
     */
    abstract protected function keyHeaders(string $key): array;

    /**
     * What the service is sent to reason as little as it can, or not at all.
     *
     * @return array<string, mixed>
     */
    abstract protected function reasoningOptions(Reasoning $reasoning): array;

    public function keySetting(): string
    {
        return $this->setting('key');
    }

    public function environmentVariable(): string
    {
        return 'TEME_AI_'.strtoupper(str_replace('-', '_', $this->key())).'_KEY';
    }

    /**
     * Whether the key is set, and where from; never the key.
     */
    public function keyState(): AiKeyState
    {
        $status = $this->secrets->status(AiSettings::KEY, $this->keySetting());

        return match (true) {
            ! $status['isSet'] => AiKeyState::NotSet,
            $status['source'] === 'environment' => AiKeyState::SetByServer,
            default => AiKeyState::Set,
        };
    }

    public function ready(): bool
    {
        return $this->keyState() !== AiKeyState::NotSet;
    }

    public function models(): array
    {
        $models = ShippedModels::for($this->key());
        $other = $this->otherModel();

        if ($other !== null) {
            $models[] = $other;
        }

        return $models;
    }

    /**
     * How the model reasons: the list's mark, the other model's box, or none.
     */
    public function reasoning(string $model): Reasoning
    {
        $listed = ShippedModels::reasoning($this->key(), $model);

        if ($listed !== null) {
            return $listed;
        }

        return $this->otherModel()?->id === $model && $this->values->bool(AiSettings::KEY, $this->setting('other_reasons'))
            ? Reasoning::Lowest
            : Reasoning::None;
    }

    public function complete(AiRequest $request): AiResult
    {
        $key = $this->secrets->secret(AiSettings::KEY, $this->keySetting());

        if ($key === null) {
            throw AiFailure::unavailable();
        }

        $system = '';
        $users = [];

        foreach ($request->messages as $message) {
            if ($message->role === 'system') {
                $system = $message->text;
            } else {
                $users[] = $message->text;
            }
        }

        $last = array_pop($users) ?? '';
        $earlier = array_map(fn (string $text): UserMessage => new UserMessage($text), $users);
        $reasoning = $this->reasoning($request->model);
        $limit = $request->maxOutputTokens + ($reasoning === Reasoning::Lowest ? self::REASONING_ALLOWANCE : 0);
        $options = $this->reasoningOptions($reasoning);
        $agent = $request->schema === null
            ? new RequestAgent($system, $earlier, $limit, $options)
            : new StructuredRequestAgent($system, $earlier, $limit, $options, $this->outputTypes($request->schema));
        $images = array_map(fn (AiImage $image): Base64Image => new Base64Image($image->base64(), $image->mediaType), $request->images);
        $started = hrtime(true);

        try {
            $provider = Ai::build(['name' => 'teme-'.$this->key(), 'driver' => $this->driver(), 'key' => $key]);
            $response = $agent->prompt($last, attachments: $images, provider: $provider->name(), model: $request->model, timeout: $request->timeoutSeconds);
        } catch (AiException|HttpClientException|JsonException $exception) {
            // A new failure, never the original: its message can quote the draft (I-314).
            throw $this->failure($exception);
        }

        return $this->result($request, $response, intdiv(hrtime(true) - $started, 1_000_000));
    }

    /**
     * Asks the service whether the key works, with a request that costs
     * nothing (I-315).
     */
    public function checkKey(): AiKeyCheck
    {
        $key = $this->secrets->secret(AiSettings::KEY, $this->keySetting());

        if ($key === null) {
            return AiKeyCheck::Missing;
        }

        try {
            $response = Http::withHeaders($this->keyHeaders($key))->acceptJson()->connectTimeout(5)->timeout(10)->get($this->checkUrl());
        } catch (ConnectionException) {
            return AiKeyCheck::Unreachable;
        }

        return match (true) {
            $response->successful() => AiKeyCheck::Works,
            in_array($response->status(), [401, 403], true) => AiKeyCheck::Refused,
            default => AiKeyCheck::Unreachable,
        };
    }

    public function settings(): array
    {
        $label = $this->label();

        return [
            Setting::secret($this->keySetting(), "{$label} API key", "Text you ask AI about is sent to {$label}. The key is kept encrypted and never shown again. The server can set it instead, as {$this->environmentVariable()}.", '0.1.0')
                ->env($this->environmentVariable()),
            Setting::text($this->setting('other_model'), "Another {$label} model", "A model Teme’s list doesn’t have yet, written as {$label} writes it. Save it here, then choose it under Models as {$this->key()}:its-name.", '0.1.0')
                ->maxLength(200),
            Setting::multiselect($this->setting('other_abilities'), "What the other {$label} model can do", 'Teme offers it only for the tasks it can do.', '0.1.0', self::ABILITIES),
            Setting::boolean($this->setting('other_reasons'), "The other {$label} model reasons before answering", 'Teme then asks it to reason as little as it can. Reasoning models are slower and cost more.', '0.1.0')
                ->default(false),
            Setting::text($this->setting('other_input_price'), "The other {$label} model’s input price", 'US dollars per million tokens sent to it, such as 3 or 0.25. Leave both prices empty if you don’t know them: its cost then shows as unknown, and the budget can’t count it.', '0.1.0')
                ->maxLength(20),
            Setting::text($this->setting('other_output_price'), "The other {$label} model’s output price", 'US dollars per million tokens it writes, such as 15.', '0.1.0')
                ->maxLength(20),
        ];
    }

    public function group(): string
    {
        return AiSettings::KEY;
    }

    public function problems(array $values): array
    {
        $problems = [];
        $nameKey = $this->setting('other_model');
        $abilitiesKey = $this->setting('other_abilities');
        $priceKeys = [$this->setting('other_input_price'), $this->setting('other_output_price')];
        $name = $this->text($values, $nameKey);

        if (array_key_exists($nameKey, $values) && $name !== '') {
            if (preg_match(self::MODEL_NAME, $name) !== 1) {
                $problems["values.{$nameKey}"] = "Write the model’s name as {$this->label()} writes it, without spaces.";
            } elseif (ShippedModels::has($this->key(), $name)) {
                $problems["values.{$nameKey}"] = "{$this->label()}’s list already has {$name}; choose it under Models.";
            }
        }

        if ((array_key_exists($nameKey, $values) || array_key_exists($abilitiesKey, $values)) && $name !== '' && $this->abilities($values, $abilitiesKey) === []) {
            $problems["values.{$abilitiesKey}"] = 'Choose what this model can do.';
        }

        foreach ($priceKeys as $key) {
            if (array_key_exists($key, $values) && $this->text($values, $key) !== '' && Dollars::toMicro($this->text($values, $key)) === null) {
                $problems["values.{$key}"] = 'Write a price in US dollars, such as 3 or 0.25.';
            }
        }

        [$input, $output] = [$this->text($values, $priceKeys[0]), $this->text($values, $priceKeys[1])];

        if ((array_key_exists($priceKeys[0], $values) || array_key_exists($priceKeys[1], $values)) && ($input === '') !== ($output === '')) {
            $empty = $input === '' ? $priceKeys[0] : $priceKeys[1];
            $problems["values.{$empty}"] ??= 'Write both prices, or neither.';
        }

        return $problems;
    }

    /**
     * The model an administrator added, when it has a name the list hasn't
     * and something it can do; priced only when both prices read.
     */
    private function otherModel(): ?AiModel
    {
        $name = trim($this->values->string(AiSettings::KEY, $this->setting('other_model')));
        $abilities = $this->abilities([], $this->setting('other_abilities'));

        if ($name === '' || $abilities === [] || ShippedModels::has($this->key(), $name)) {
            return null;
        }

        $input = Dollars::toMicro($this->values->string(AiSettings::KEY, $this->setting('other_input_price')));
        $output = Dollars::toMicro($this->values->string(AiSettings::KEY, $this->setting('other_output_price')));

        return new AiModel($name, $name, $abilities, $input === null || $output === null ? null : new AiPrice($input, $output));
    }

    private function setting(string $suffix): string
    {
        return str_replace('-', '_', $this->key()).'_'.$suffix;
    }

    /**
     * A text value as the form sent it, or as stored when it didn't.
     *
     * @param  array<string, mixed>  $values
     */
    private function text(array $values, string $key): string
    {
        $value = array_key_exists($key, $values) ? $values[$key] : $this->values->string(AiSettings::KEY, $key);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * The capabilities chosen, as the form sent them or as stored.
     *
     * @param  array<string, mixed>  $values
     *
     * @return list<AiCapability>
     */
    private function abilities(array $values, string $key): array
    {
        $chosen = array_key_exists($key, $values) ? $values[$key] : $this->values->stringList(AiSettings::KEY, $key);
        $abilities = [];

        foreach (is_array($chosen) ? $chosen : [] as $value) {
            $ability = is_string($value) ? AiCapability::tryFrom($value) : null;

            if ($ability !== null) {
                $abilities[] = $ability;
            }
        }

        return $abilities;
    }

    /**
     * The answer's shape as laravel/ai's types, property by property.
     *
     * @param  array<string, mixed>  $schema
     *
     * @return array<string, Type>
     */
    private function outputTypes(array $schema): array
    {
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $types = [];

        foreach (is_array($schema['properties'] ?? null) ? $schema['properties'] : [] as $name => $property) {
            $type = JsonSchema::fromArray(is_array($property) ? $property : []);
            $types[(string) $name] = in_array($name, $required, true) ? $type->required() : $type;
        }

        return $types;
    }

    private function result(AiRequest $request, AgentResponse $response, int $latencyMs): AiResult
    {
        $last = $response->steps->last();
        $finish = $last instanceof Step ? $last->finishReason : null;

        if ($finish === FinishReason::ContentFilter) {
            throw AiFailure::refused();
        }

        if ($finish === FinishReason::Length) {
            throw AiFailure::invalidOutput();
        }

        if ($finish === FinishReason::Error) {
            throw AiFailure::unavailable();
        }

        // Output already includes reasoning, so it's charged once, as output (I-1360).
        $usage = new AiUsage(
            input: max(0, $response->usage->uncachedInputTokens()),
            output: $response->usage->outputTokens,
            cacheRead: $response->usage->cacheReadInputTokens ?? 0,
            cacheWrite: $response->usage->cacheWriteInputTokens ?? 0,
        );
        $model = $response->meta->model !== null && $response->meta->model !== '' ? $response->meta->model : $request->model;

        if ($request->schema !== null) {
            $data = $response instanceof StructuredAgentResponse ? $response->structured : [];

            return $data === [] ? throw AiFailure::invalidOutput() : new AiResult($model, $usage, $latencyMs, data: $data);
        }

        return trim($response->text) === '' ? throw AiFailure::invalidOutput() : new AiResult($model, $usage, $latencyMs, text: $response->text);
    }

    private function failure(Throwable $exception): AiFailure
    {
        return match (true) {
            $exception instanceof RateLimitedException => AiFailure::rateLimited($this->retryAfter($exception)),
            $exception instanceof ProviderConnectionException, $exception instanceof ConnectionException => $this->timedOut($exception) ? AiFailure::timedOut() : AiFailure::unavailable(),
            $exception instanceof RequestException => match (true) {
                $exception->response->status() === 408 => AiFailure::timedOut(),
                in_array($exception->response->status(), [400, 413, 422], true) => AiFailure::refused(),
                default => AiFailure::unavailable(),
            },
            $exception instanceof JsonException => AiFailure::invalidOutput(),
            default => AiFailure::unavailable(),
        };
    }

    private function retryAfter(Throwable $exception): ?int
    {
        $previous = $exception->getPrevious();
        $seconds = $previous instanceof RequestException ? $previous->response->header('retry-after') : '';

        return ctype_digit($seconds) ? (int) $seconds : null;
    }

    private function timedOut(Throwable $exception): bool
    {
        $connection = $exception instanceof ConnectionException ? $exception : $exception->getPrevious();

        return $connection instanceof ConnectionException && str_contains($connection->getMessage(), 'cURL error 28');
    }
}

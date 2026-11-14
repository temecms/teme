<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * A model as a setting names it: provider:model. The provider key is
 * everything before the first colon; the model is the rest, as the provider
 * names it, so an OpenRouter model may hold its own slashes and colons.
 */
final readonly class ModelRef
{
    public function __construct(public string $provider, public string $model) {}

    public static function parse(string $value): ?self
    {
        $value = trim($value);
        $colon = strpos($value, ':');

        if ($colon === false) {
            return null;
        }

        $provider = substr($value, 0, $colon);
        $model = trim(substr($value, $colon + 1));

        if (preg_match(AiKeys::PATTERN, $provider) !== 1 || $model === '') {
            return null;
        }

        return new self($provider, $model);
    }

    public function toString(): string
    {
        return "{$this->provider}:{$this->model}";
    }
}

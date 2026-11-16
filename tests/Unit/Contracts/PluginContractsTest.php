<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\Input\SchedulePublicationInput;
use App\Domain\Plugins\HostPattern;
use App\Domain\Themes\ManifestValidator;
use Teme\Contracts\Ai\AiAnswer;
use Teme\Contracts\Ai\AiAsk;
use Teme\Contracts\Ai\AiRefused;
use Teme\Contracts\Ai\AiStanding;
use Teme\Contracts\Ai\SiteAi;
use Teme\Contracts\Content\ContentWriter;
use Teme\Contracts\Content\ItemChanges;
use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Content\NewItem;
use Teme\Contracts\Content\WriteRefused;
use Teme\Contracts\Http\AnswerTooLarge;
use Teme\Contracts\Http\HostRefused;
use Teme\Contracts\Http\HttpAnswer;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Http\RequestFailed;
use Teme\Contracts\Insertions\InlineScripts;
use Teme\Contracts\Insertions\PageFacts;
use Teme\Contracts\Insertions\PageInsertion;
use Teme\Contracts\Insertions\PageInsertionContributor;
use Teme\Contracts\Insertions\PagePosition;
use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;
use Teme\Contracts\Jobs\Jobs;
use Teme\Contracts\Jobs\JobStopped;
use Teme\Contracts\Jobs\PluginHasJobs;
use Teme\Contracts\Jobs\QueueOptions;
use Teme\Contracts\Lists\Cell;
use Teme\Contracts\Lists\HasListColumns;
use Teme\Contracts\Lists\HasPluginLists;
use Teme\Contracts\Lists\ListColumn;
use Teme\Contracts\Lists\ListRow;
use Teme\Contracts\Lists\ListRows;
use Teme\Contracts\Lists\PluginList;
use Teme\Contracts\Media\MediaWriter;
use Teme\Contracts\Plugins\Capability;
use Teme\Contracts\Settings\PluginSettingsWriter;
use Teme\Contracts\Site\SiteInfo;
use Teme\Contracts\Status\ChecksStatus;
use Teme\Contracts\Status\StatusCheck;
use Teme\Contracts\Status\StatusNotice;

it('gives every new contract its @since and nothing from a framework', function (string $class, string $since, array $later = []): void {
    $reflection = new ReflectionClass($class);

    expect($reflection->getDocComment())->toContain('@since '.$since);

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class || ! $method->isUserDefined()) {
            continue;
        }

        // A method added later carries the release it arrived in (.ai/rules/contracts.md).
        expect($method->getDocComment())->toContain('@since '.($later[$method->getName()] ?? $since));
    }

    $types = [];

    foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        $types[] = $property->getType();
    }

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class) {
            continue;
        }

        $types[] = $method->getReturnType();

        foreach ($method->getParameters() as $parameter) {
            $types[] = $parameter->getType();
        }
    }

    foreach (array_filter($types) as $type) {
        $named = $type instanceof ReflectionNamedType ? [$type] : $type->getTypes();

        foreach ($named as $one) {
            if ($one->isBuiltin() || in_array($one->getName(), ['self', 'static', DateTimeImmutable::class], true)) {
                continue;
            }

            expect($one->getName())->toStartWith('Teme\\Contracts\\');
        }
    }
})->with([
    [Capability::class, '0.5.0'],
    [StatusCheck::class, '0.5.0'],
    [StatusNotice::class, '0.5.0'],
    [ChecksStatus::class, '0.5.0'],
    [OutboundHttp::class, '0.5.0', ['postForm' => '0.9.0']],
    [HttpAnswer::class, '0.5.0'],
    [HostRefused::class, '0.5.0'],
    [AnswerTooLarge::class, '0.5.0'],
    [RequestFailed::class, '0.5.0'],
    [ItemRef::class, '0.5.0'],
    [ContentWriter::class, '0.9.0'],
    [NewItem::class, '0.9.0'],
    [ItemChanges::class, '0.9.0'],
    [WriteRefused::class, '0.9.0'],
    [MediaWriter::class, '0.9.0'],
    [HasListColumns::class, '0.9.0'],
    [ListColumn::class, '0.9.0'],
    [ListRows::class, '0.9.0'],
    [ListRow::class, '0.9.0'],
    [Cell::class, '0.9.0'],
    [SiteInfo::class, '0.9.0'],
    [PluginSettingsWriter::class, '0.9.0'],
    [QueueOptions::class, '0.9.0'],
    [HasPluginLists::class, '0.9.0'],
    [PluginList::class, '0.9.0'],
    [PageInsertionContributor::class, '0.9.0'],
    [PageInsertion::class, '0.9.0'],
    [PagePosition::class, '0.9.0'],
    [PageFacts::class, '0.9.0'],
    [InlineScripts::class, '0.9.0'],
    [SiteAi::class, '0.9.0'],
    [AiAsk::class, '0.9.0'],
    [AiAnswer::class, '0.9.0'],
    [AiStanding::class, '0.9.0'],
    [AiRefused::class, '0.9.0'],
]);

it('does not accept a language on ItemChanges or via on publish and schedule inputs', function (): void {
    expect((new ReflectionClass(ItemChanges::class))->hasProperty('language'))->toBeFalse()
        ->and((new ReflectionClass(PublishRevisionInput::class))->hasProperty('via'))->toBeFalse()
        ->and((new ReflectionClass(SchedulePublicationInput::class))->hasProperty('via'))->toBeFalse();
});

it('names the capabilities a plugin can ask for in the first release', function (): void {
    expect(array_column(Capability::cases(), 'value'))->toBe([
        'content.read',
        'content.write',
        'media.read',
        'media.write',
        'content.insert',
        'http',
        'jobs',
        'routes.public',
        'import.wordpress',
        'fediverse.read',
        'fediverse.send',
        'feeds.links',
        'pages.insert',
        'ai',
    ]);
});

it('gives every jobs contract its @since 0.9.0 and nothing from a framework', function (string $class): void {
    $reflection = new ReflectionClass($class);

    expect($reflection->getDocComment())->toContain('@since 0.9.0');

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class || ! $method->isUserDefined()) {
            continue;
        }

        expect($method->getDocComment())->toContain('@since 0.9.0');
    }

    $types = [];

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class) {
            continue;
        }

        $types[] = $method->getReturnType();

        foreach ($method->getParameters() as $parameter) {
            $types[] = $parameter->getType();
        }
    }

    foreach (array_filter($types) as $type) {
        $named = $type instanceof ReflectionNamedType ? [$type] : $type->getTypes();

        foreach ($named as $one) {
            if ($one->isBuiltin() || in_array($one->getName(), ['self', 'static'], true)) {
                continue;
            }

            expect($one->getName())->toStartWith('Teme\\Contracts\\');
        }
    }
})->with([
    Jobs::class,
    JobHandler::class,
    JobContext::class,
    JobStopped::class,
    PluginHasJobs::class,
]);

it('decodes an answer’s JSON, and refuses JSON that isn’t', function (): void {
    expect((new HttpAnswer(200, '{"a":1}', []))->json())->toBe(['a' => 1]);

    expect(fn () => (new HttpAnswer(200, 'nope', []))->json())->toThrow(JsonException::class);
});

it('keeps the schema in step with the capability names and the host rule', function (): void {
    $schema = json_decode((string) file_get_contents(base_path('packages/teme-contracts/schema/teme-plugin.schema.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($schema['properties']['capabilities']['items']['enum'])->toBe(array_column(Capability::cases(), 'value'))
        ->and('/'.$schema['properties']['hosts']['items']['pattern'].'/D')->toBe(HostPattern::PATTERN)
        ->and($schema['properties']['code']['pattern'])->toBe('^'.ManifestValidator::CODE_PATTERN.'$');
});

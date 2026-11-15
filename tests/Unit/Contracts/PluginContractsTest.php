<?php

declare(strict_types=1);

use App\Domain\Plugins\HostPattern;
use App\Domain\Themes\ManifestValidator;
use Teme\Contracts\Http\AnswerTooLarge;
use Teme\Contracts\Http\HostRefused;
use Teme\Contracts\Http\HttpAnswer;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Http\RequestFailed;
use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;
use Teme\Contracts\Jobs\Jobs;
use Teme\Contracts\Jobs\JobStopped;
use Teme\Contracts\Jobs\PluginHasJobs;
use Teme\Contracts\Plugins\Capability;
use Teme\Contracts\Status\ChecksStatus;
use Teme\Contracts\Status\StatusCheck;
use Teme\Contracts\Status\StatusNotice;

it('gives every new contract its @since and nothing from a framework', function (string $class): void {
    $reflection = new ReflectionClass($class);

    expect($reflection->getDocComment())->toContain('@since 0.5.0');

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class || ! $method->isUserDefined()) {
            continue;
        }

        expect($method->getDocComment())->toContain('@since 0.5.0');
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
            if ($one->isBuiltin() || in_array($one->getName(), ['self', 'static'], true)) {
                continue;
            }

            expect($one->getName())->toStartWith('Teme\\Contracts\\');
        }
    }
})->with([
    Capability::class,
    StatusCheck::class,
    StatusNotice::class,
    ChecksStatus::class,
    OutboundHttp::class,
    HttpAnswer::class,
    HostRefused::class,
    AnswerTooLarge::class,
    RequestFailed::class,
]);

it('names the capabilities a plugin can ask for in the first release', function (): void {
    expect(array_column(Capability::cases(), 'value'))->toBe(['content.read', 'media.read', 'http', 'routes.public', 'jobs', 'fediverse.read', 'fediverse.send']);
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

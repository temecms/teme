<?php

declare(strict_types=1);

use Teme\Contracts\Notices\NoticeKind;
use Teme\Contracts\Notices\NoticeMessage;
use Teme\Contracts\Notices\NoticeRegistrar;
use Teme\Contracts\Notices\Notices;

it('gives every notice contract its @since, its preview line and nothing from a framework', function (string $class): void {
    $reflection = new ReflectionClass($class);

    expect($reflection->getDocComment())->toContain('@since 0.5.0')
        ->and(preg_replace('/\s*\n\s*\*\s*/', ' ', (string) $reflection->getDocComment()))->toContain('A preview until Teme 1.0.');

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
    NoticeKind::class,
    NoticeMessage::class,
    NoticeRegistrar::class,
    Notices::class,
]);

it('gives the kind its constants a @since too', function (): void {
    foreach (['MAIL', 'INBOX'] as $name) {
        expect((new ReflectionClassConstant(NoticeKind::class, $name))->getDocComment())->toContain('@since 0.5.0');
    }

    expect(NoticeKind::MAIL)->toBe('mail')
        ->and(NoticeKind::INBOX)->toBe('inbox');
});

it('keeps the kind’s parameters in the order the plan wrote, the reason last', function (): void {
    $names = array_map(
        fn (ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionClass(NoticeKind::class))->getConstructor()->getParameters(),
    );

    expect($names)->toBe(['key', 'label', 'audience', 'channels', 'defaults', 'required', 'reason']);
});

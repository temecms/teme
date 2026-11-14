<?php

declare(strict_types=1);

use Teme\Contracts\Hooks\Failure;
use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Privacy\CollectPersonalDataHolders;
use Teme\Contracts\Privacy\ErasePage;
use Teme\Contracts\Privacy\ExportGroup;
use Teme\Contracts\Privacy\ExportPage;
use Teme\Contracts\Privacy\PersonalDataHolder;
use Teme\Contracts\Privacy\PersonalDataSubject;

it('gives every privacy contract its @since, the preview line and nothing from a framework', function (string $class): void {
    $reflection = new ReflectionClass($class);

    expect($reflection->getDocComment())->toContain('@since 0.5.0')
        ->and($reflection->getDocComment())->toContain('A preview until Teme 1.0 (I-3187)');

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
    PersonalDataSubject::class,
    PersonalDataHolder::class,
    ExportGroup::class,
    ExportPage::class,
    ErasePage::class,
    CollectPersonalDataHolders::class,
]);

it('gives each privacy value class exactly its properties, in order, with a default only for the account', function (string $class, array $properties): void {
    $parameters = (new ReflectionMethod($class, '__construct'))->getParameters();
    $withDefault = array_values(array_map(
        fn (ReflectionParameter $parameter): string => $parameter->getName(),
        array_filter($parameters, fn (ReflectionParameter $parameter): bool => $parameter->isDefaultValueAvailable()),
    ));

    expect(array_map(fn (ReflectionProperty $property): string => $property->getName(), (new ReflectionClass($class))->getProperties()))->toBe($properties)
        ->and(array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), $parameters))->toBe($properties)
        ->and($withDefault)->toBe($class === PersonalDataSubject::class ? ['accountId'] : []);
})->with([
    [PersonalDataSubject::class, ['email', 'accountId']],
    [ExportGroup::class, ['title', 'values']],
    [ExportPage::class, ['groups', 'done']],
    [ErasePage::class, ['removed', 'retained', 'reasons', 'done']],
]);

it('collects holders of personal data, with no capability, and fails the operation when a listener fails', function (): void {
    $contract = (new ReflectionClass(CollectPersonalDataHolders::class))->getAttributes(HookContract::class)[0]->newInstance();

    expect(CollectPersonalDataHolders::itemType())->toBe(PersonalDataHolder::class)
        ->and($contract->capability)->toBeNull()
        ->and($contract->failure)->toBe(Failure::FailOperation);
});

<?php

declare(strict_types=1);

use App\Domain\Hooks\Reference\HookCatalogue;
use Composer\Semver\Comparator;
use Symfony\Component\Finder\Finder;
use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Hooks\Collect;
use Teme\Contracts\Hooks\Failure;
use Teme\Contracts\Hooks\Gate;
use Teme\Contracts\Hooks\Hook;
use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Notification;
use Teme\Contracts\Hooks\Order;
use Teme\Contracts\Hooks\Resolve;
use Teme\Contracts\Hooks\Stage;
use Teme\Contracts\Hooks\Transform;
use Teme\Contracts\Media\MediaRef;

it('marks every hook with one verb, a @since and its contract', function (string $hook): void {
    $class = new ReflectionClass($hook);
    $verbs = array_filter([Notification::class, Transform::class, Gate::class, Resolve::class, Collect::class], fn (string $verb): bool => $class->implementsInterface($verb));

    expect($verbs)->toHaveCount(1)
        ->and((string) $class->getDocComment())->toMatch('/@since (next|\d+\.\d+\.\d+)/')
        ->and($class->getAttributes(HookContract::class))->toHaveCount(1)
        ->and($class->isFinal() && $class->isReadOnly())->toBeTrue();
})->with(fn (): array => HookCatalogue::HOOKS);

it('lets only a transform or a collect fail the operation', function (string $hook): void {
    $contract = (new ReflectionClass($hook))->getAttributes(HookContract::class)[0]->newInstance();
    $allowed = is_subclass_of($hook, Transform::class) || is_subclass_of($hook, Collect::class);

    expect($contract->failure === Failure::SkipListener || $allowed)->toBeTrue();
})->with(fn (): array => HookCatalogue::HOOKS);

it('agrees with the docblock on a deprecation and gives a release of warning', function (string $hook): void {
    $class = new ReflectionClass($hook);
    $contract = $class->getAttributes(HookContract::class)[0]->newInstance();
    $docblock = (string) $class->getDocComment();

    if ($contract->deprecated === null) {
        expect($docblock)->not->toContain('@deprecated')
            ->and($contract->removedIn)->toBeNull()
            ->and($contract->use)->toBeNull();

        return;
    }

    $minor = fn (?string $version): string => (string) preg_replace('/^(\d+)\.(\d+).*$/', '$1.$2.0', (string) $version);
    $use = (new ReflectionClass((string) $contract->use))->getShortName();

    expect($docblock)->toContain("@deprecated {$contract->deprecated} Use {$use}. Removed in {$contract->removedIn}.")
        ->and(Comparator::greaterThan($minor($contract->removedIn), $minor($contract->deprecated)))->toBeTrue();
})->with(fn (): array => HookCatalogue::HOOKS);

it('builds an order from a stage and the plugins it runs after or before, without changing the one it came from', function (): void {
    $late = Order::late();
    $order = $late->after('acme/a')->before('acme/b', 'acme/c');

    expect($order->stage)->toBe(Stage::Late)->and($order->after)->toBe(['acme/a'])->and($order->before)->toBe(['acme/b', 'acme/c'])
        ->and($late->after)->toBe([])->and(Order::normal()->stage)->toBe(Stage::Normal);
});

it('gives every payload value class plain properties only', function (string $class): void {
    $properties = (new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC);
    $types = array_map(fn (ReflectionProperty $property): string => (string) $property->getType(), $properties);

    expect($properties)->not->toBeEmpty()
        ->and(array_diff($types, ['string', '?string', 'int', '?int', 'bool']))->toBe([]);
})->with([ItemRef::class, MediaRef::class]);

it('lists every hook class the contracts package has, and no other', function (): void {
    $source = dirname(__DIR__, 3).'/packages/teme-contracts/src';
    $markers = [Hook::class, Notification::class, Transform::class, Gate::class, Resolve::class, Collect::class];
    $hooks = [];

    foreach ((new Finder)->files()->in($source)->name('*.php') as $file) {
        $class = 'Teme\\Contracts\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if ((class_exists($class) || interface_exists($class)) && is_subclass_of($class, Hook::class) && ! in_array($class, $markers, true)) {
            $hooks[] = $class;
        }
    }

    expect($hooks)->toEqualCanonicalizing(HookCatalogue::HOOKS);
});

it('gives every hook a unique short name', function (): void {
    $names = array_map(fn (string $hook): string => (new ReflectionClass($hook))->getShortName(), HookCatalogue::HOOKS);

    expect($names)->toHaveCount(27)->and(array_unique($names))->toBe($names);
});

it('gives an item reference its first values in this order, and anything added later at the end with a default (I-3360, contracts rule)', function (): void {
    $parameters = (new ReflectionMethod(ItemRef::class, '__construct'))->getParameters();
    $first = array_slice($parameters, 0, 6);
    $later = array_slice($parameters, 6);

    expect(array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), $first))->toBe(['id', 'type', 'title', 'status', 'url', 'language'])
        ->and(array_filter($first, fn (ReflectionParameter $parameter): bool => $parameter->isDefaultValueAvailable()))->toBe([])
        ->and(array_filter($later, fn (ReflectionParameter $parameter): bool => ! $parameter->isDefaultValueAvailable()))->toBe([]);
});

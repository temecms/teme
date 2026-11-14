<?php

declare(strict_types=1);

use App\Domain\Themes\ViewIntent;
use Teme\Contracts\Themes\ViewComposer;
use Teme\Contracts\Themes\ViewContext;

it('offers the two methods a theme composer needs, with no framework types', function (): void {
    $composer = new ReflectionClass(ViewComposer::class);
    $context = new ReflectionClass(ViewContext::class);

    expect($composer->isInterface())->toBeTrue()
        ->and(array_map(fn (ReflectionMethod $m): string => $m->getName(), $composer->getMethods()))->toBe(['views', 'with'])
        ->and(array_map(fn (ReflectionMethod $m): string => $m->getName(), $context->getMethods()))->toBe(['view', 'kind', 'isFront', 'locale', 'has', 'get']);

    foreach ([$composer, $context] as $class) {
        foreach ($class->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                expect((string) $parameter->getType())->not->toContain('Illuminate');
            }
            expect((string) $method->getReturnType())->not->toContain('Illuminate');
        }
    }
});

it('names every kind of page Teme draws in kind()’s description', function (): void {
    $doc = (string) (new ReflectionMethod(ViewContext::class, 'kind'))->getDocComment();

    foreach (ViewIntent::KINDS as $kind) {
        expect($doc)->toMatch('/\b'.preg_quote($kind, '/').'\b/');
    }

    expect($doc)->toContain('@since 0.1.0');
});

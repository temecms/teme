<?php

declare(strict_types=1);

/**
 * Every domain model namespace — App\Domain\<Module>\Models — read from the
 * filesystem, so a module added later is covered without editing this file.
 * Plain PHP, because arch expectations are built before Laravel boots.
 *
 * @return list<string>
 */
function domainModelNamespaces(): array
{
    $directories = glob(dirname(__DIR__, 2).'/app/Domain/*/Models', GLOB_ONLYDIR) ?: [];

    return array_values(array_map(
        static fn (string $directory): string => 'App\\Domain\\'.basename(dirname($directory)).'\\Models',
        $directories,
    ));
}

// Two separate expectations, not expect(['App\Http', 'App\Actions']): Pest's
// arch plugin silently passes the *whole* combined expectation whenever any
// one of several expect() namespaces has zero matching files on disk (as
// App\Actions does before Step 5, and again after Step 6 removes it here).
// One expect() per namespace keeps each independently enforced regardless
// of whether the other currently has any files.
arch('http layer never touches Eloquent models')
    ->expect('App\Http')
    ->not->toUse([
        'Illuminate\Database\Eloquent\Model',
        'Illuminate\Database\Eloquent\Builder',
        'Illuminate\Support\Facades\DB',
        'App\Models',
        ...domainModelNamespaces(),
    ]);

arch('actions layer never touches Eloquent models')
    ->expect('App\Actions')
    ->not->toUse([
        'Illuminate\Database\Eloquent\Model',
        'Illuminate\Database\Eloquent\Builder',
        'Illuminate\Support\Facades\DB',
        'App\Models',
        ...domainModelNamespaces(),
    ]);

arch('cms domain never depends on the http layer')
    ->expect('App\Domain')
    ->not->toUse('App\Http');

arch('contracts package depends on nothing in the application')
    ->expect('Teme\Contracts')
    ->not->toUse('App');

arch('contracts package exposes no Laravel types')
    ->expect('Teme\Contracts')
    ->not->toUse(['Illuminate', 'Carbon']);

arch('strict types in code we write')
    ->expect(['App\Domain', 'Teme\Contracts'])
    ->toUseStrictTypes();

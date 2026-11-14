<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * I-087: code we write keeps no state between requests, so it stays safe
 * under Laravel Octane, and one reader's state never reaches a page the page
 * cache stores for everyone. A static property, or a static variable in a
 * function, is shared by every request a process serves; request state
 * lives in a scoped instance, as RenderRecord and DesignPreview do.
 * Constants are fine.
 */
it('keeps no static property or static variable in code we write (I-087)', function (): void {
    $root = dirname(__DIR__, 2);
    $static = '/^\s*(?:(?:public|protected|private|readonly|final)\s+)*static\s+(?:\??[\w\\\\|]+\s+)?\$/m';
    $offenders = [];

    foreach ((new Finder)->files()->in([$root.'/app', $root.'/packages'])->name('*.php') as $file) {
        if (preg_match($static, $file->getContents()) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});

/*
 * I-087, the answer to 1.7e's question 1 (A): a container singleton
 * is shared by every request and job a process serves, as a static is, and
 * Laravel forgets only scoped instances between them, the queue worker
 * before each job and Octane before each request. So a class of ours is
 * bound once for the whole process only when it holds nothing but what boot
 * gives it: one of the registries below, filled by service providers as the
 * application boots, or one that keeps nothing that changes. Anything a
 * request or job learns lives in a scoped instance, as Actors, RenderRecord
 * and CardMedia do. A new singleton needs the architect's reason on this
 * list, or scoped().
 */
it('binds once for the whole process only what boot fills (I-087)', function (): void {
    $root = dirname(__DIR__, 2);
    $registries = [
        // Filled by service providers as the application boots.
        'CardRegistry', 'CommentStatusRegistry', 'DashboardRegistry', 'Doctor', 'FieldRegistry', 'NavigationRegistry', 'PreferenceRegistry', 'RequiredProperties', 'SettingsRegistry', 'StatusChecks', 'Vocabulary',
        'ToolLinks',
        // Filled as plugins boot; a plugin's subscriptions close when its register() ends (I-3353).
        'HookDispatcher',
        // Keep nothing that changes: an image driver, and the settings
        // registry (values go through Cache::memo(), which is scoped).
        'ImageEngine', 'SecretStore', 'SettingsRepository',
        // Plugins found and started once a process (I-3353).
        'PluginRegistry',
        'LoadedPlugins',
        'HookSubscriptions',
    ];
    $bound = [];

    foreach ((new Finder)->files()->in([$root.'/app', $root.'/packages'])->name('*.php') as $file) {
        $code = $file->getContents();

        preg_match_all('/->singleton(?:If)?\(\s*([^,)]+?)\s*[,)]/', $code, $calls);

        foreach ($calls[1] as $abstract) {
            $bound[] = (string) preg_replace('/::class$/', '', $abstract);
        }

        // A provider's $singletons, or the container's #[Singleton] attribute.
        if (preg_match('/\$singletons\s*=|#\[[^\]]*\bSingleton\b/', $code) === 1) {
            $bound[] = $file->getRelativePathname();
        }
    }

    expect(array_values(array_diff($bound, $registries)))->toBe([]);
});

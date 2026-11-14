<?php

declare(strict_types=1);

// Every test runs withoutVite(), so a view naming a file that no longer exists
// only fails in a real browser — as the welcome page did after the skeleton's
// resources/js/app.js was removed. This closes that gap for static entries.
it('only asks Vite for files that exist', function (): void {
    $missing = [];

    foreach (glob(resource_path('views').'/*.blade.php') ?: [] as $view) {
        preg_match_all('/@vite\\(\\[?([^)]*)\\]?\\)/', (string) file_get_contents($view), $directives);

        foreach ($directives[1] as $arguments) {
            preg_match_all("/'(resources\\/[^']+)'/", $arguments, $entries);

            foreach ($entries[1] as $entry) {
                if (! file_exists(base_path($entry))) {
                    $missing[] = basename($view).' -> '.$entry;
                }
            }
        }
    }

    expect($missing)->toBe([]);
});

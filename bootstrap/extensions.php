<?php

declare(strict_types=1);

// Loads the plugins and themes installed from the admin (docs/architecture/plugins.md).
(static function (): void {
    $root = getenv('TEME_EXTENSIONS_PATH');
    $pins = getenv('TEME_CORE_PINS') ?: __DIR__.'/cache/teme-core-pins.php';

    if (! is_string($root) || $root === '' || getenv('TEME_EXTENSIONS_ROLE') === 'installer' || ! is_file($pins)) {
        return;
    }

    // readlink(), never realpath(): the realpath cache would keep the old release for minutes.
    $target = @readlink($root.'/current');

    if ($target === false) {
        return;
    }

    $release = $root.'/releases/'.basename($target);
    $hash = (require $pins)['hash'];

    if (! is_file("{$release}/compat/{$hash}") || ! is_file("{$release}/vendor/autoload.php")) {
        define('TEME_EXTENSIONS_HELD', $release);

        return;
    }

    define('TEME_EXTENSIONS_RELEASE', $release);
    define('TEME_EXTENSIONS_CORE', $hash);
    require "{$release}/vendor/autoload.php";
})();

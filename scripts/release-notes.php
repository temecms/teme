<?php

declare(strict_types=1);

/*
 * The notes of one GitHub Release, for the image workflow's release job
 * (docs/guides/operators/updates.md): the version's section of the
 * changelog without its heading, then the line Teme's update check reads.
 * Runs on the runner's own PHP, 8.3 or later, with nothing installed.
 *
 *   php scripts/release-notes.php CHANGELOG.md 0.1.0-rc.14 [added migration file ...]
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php scripts/release-notes.php <changelog> <version> [added migration file ...]\n");
    exit(2);
}

$changelog = $argv[1];
$version = $argv[2];
$migrations = array_slice($argv, 3);
$text = is_file($changelog) ? (string) file_get_contents($changelog) : '';
$heading = '/^## \['.preg_quote($version, '/').'\](\s|$)/';
$section = null;

foreach (preg_split('/\R/', $text) ?: [] as $line) {
    if ($section === null) {
        if (preg_match($heading, $line) === 1) {
            $section = [];
        }

        continue;
    }

    if (str_starts_with($line, '## [')) {
        break;
    }

    $section[] = $line;
}

if ($section === null) {
    fwrite(STDERR, "{$changelog} has no section for {$version}. Write it before tagging.\n");
    exit(1);
}

$notes = trim(implode("\n", $section));
$maintenance = false;

foreach ($migrations as $file) {
    if (is_file($file) && str_contains((string) file_get_contents($file), 'BreaksPreviousRelease')) {
        $maintenance = true;
    }
}

$marks = json_encode([
    'format' => 1,
    'security' => preg_match('/^### Security\b/m', $notes) === 1,
    'maintenance' => $maintenance,
], JSON_THROW_ON_ERROR);

echo $notes, "\n\n<!-- teme-release ", $marks, " -->\n";

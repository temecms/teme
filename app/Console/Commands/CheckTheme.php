<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Themes\ActiveTheme;
use App\Domain\Themes\Theme;
use App\Domain\Themes\ThemeRegistry;
use App\Domain\Themes\ThemeScripts;
use Illuminate\Console\Command;

/**
 * `teme:theme:check {code?}`: the preflight check for a theme, the active one
 * by default. It lists every manifest error, finds the built assets and
 * measures each file compressed, and warns past the budgets (study Q §4:
 * 15 KB of CSS, 5 KB of JavaScript). It refuses an inline script in the
 * theme's views. It fails when there are errors.
 */
final class CheckTheme extends Command
{
    /** Compressed bytes of CSS a page may load before a warning. */
    private const int CSS_BUDGET = 15 * 1024;

    /** Compressed bytes of JavaScript a page may load before a warning. */
    private const int JS_BUDGET = 5 * 1024;

    /** @var string */
    protected $signature = 'teme:theme:check {code? : The theme\'s code; the active theme by default} {--json : Print the report as JSON}';

    /** @var string */
    protected $description = 'Check a theme\'s manifest and built assets before it is used';

    public function handle(ThemeRegistry $themes, ActiveTheme $active): int
    {
        $code = (string) ($this->argument('code') ?? $active->chosen());
        $theme = $themes->find($code);
        $report = $theme === null
            ? ['code' => $code, 'errors' => ["No theme has the code \"{$code}\"."], 'warnings' => [], 'files' => [], 'css' => 0, 'js' => 0]
            : $this->check($theme);

        $this->option('json')
            ? $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            : $this->show($report);

        return $report['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{code: string, errors: list<string>, warnings: list<string>, files: list<array{file: string, bytes: int}>, css: int, js: int}
     */
    private function check(Theme $theme): array
    {
        // Inline script stops working once the public site's policy is enforced (I-3375).
        $errors = [...$theme->errors, ...ThemeScripts::refusals($theme->path)];
        $warnings = [];
        $files = [];
        $manifest = $theme->manifest;
        $built = $manifest?->assetsManifest() ?? '';
        $entries = $manifest?->assetEntries() ?? [];

        if ($built !== '' && $entries !== []) {
            $path = $theme->path.'/'.$built;
            $chunks = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

            if (! is_array($chunks)) {
                $errors[] = "The theme's built assets are missing: {$built} does not exist. Run its build.";
            } else {
                foreach ($entries as $entry) {
                    $chunk = $chunks[$entry] ?? null;

                    if (! is_array($chunk) || ! is_string($chunk['file'] ?? null)) {
                        $errors[] = "The entry {$entry} is not in {$built}.";

                        continue;
                    }

                    foreach ([$chunk['file'], ...array_filter((array) ($chunk['css'] ?? []), 'is_string')] as $file) {
                        $bytes = $this->compressed(dirname($path).'/'.$file);

                        $bytes === null
                            ? $errors[] = "The built file {$file} is missing."
                            : $files[$file] = ['file' => $file, 'bytes' => $bytes];
                    }
                }
            }
        }

        $css = array_sum(array_map(fn (array $file): int => str_ends_with($file['file'], '.css') ? $file['bytes'] : 0, $files));
        $js = array_sum(array_map(fn (array $file): int => str_ends_with($file['file'], '.js') ? $file['bytes'] : 0, $files));

        if ($css > self::CSS_BUDGET) {
            $warnings[] = sprintf('CSS is %.1f KB compressed, over the %d KB budget.', $css / 1024, self::CSS_BUDGET / 1024);
        }

        if ($js > self::JS_BUDGET) {
            $warnings[] = sprintf('JavaScript is %.1f KB compressed, over the %d KB budget.', $js / 1024, self::JS_BUDGET / 1024);
        }

        return ['code' => $theme->code, 'errors' => $errors, 'warnings' => $warnings, 'files' => array_values($files), 'css' => $css, 'js' => $js];
    }

    /**
     * The file's size compressed with gzip at level 9, or null when it isn't there.
     */
    private function compressed(string $path): ?int
    {
        if (! is_file($path)) {
            return null;
        }

        return strlen((string) gzencode((string) file_get_contents($path), 9));
    }

    /**
     * @param  array{code: string, errors: list<string>, warnings: list<string>, files: list<array{file: string, bytes: int}>, css: int, js: int}  $report
     */
    private function show(array $report): void
    {
        $this->components->twoColumnDetail('Theme', $report['code']);

        foreach ($report['errors'] as $error) {
            $this->components->error($error);
        }

        if ($report['files'] !== []) {
            $this->table(['Built file', 'Compressed'], array_map(fn (array $file): array => [$file['file'], sprintf('%.1f KB', $file['bytes'] / 1024)], $report['files']));
        }

        $this->components->twoColumnDetail('CSS', sprintf('%.1f KB of %d KB', $report['css'] / 1024, self::CSS_BUDGET / 1024));
        $this->components->twoColumnDetail('JavaScript', sprintf('%.1f KB of %d KB', $report['js'] / 1024, self::JS_BUDGET / 1024));

        foreach ($report['warnings'] as $warning) {
            $this->components->warn($warning);
        }

        $report['errors'] === []
            ? $this->components->info('The theme passes.')
            : $this->components->error(sprintf('The theme has %d %s.', count($report['errors']), count($report['errors']) === 1 ? 'error' : 'errors'));
    }
}

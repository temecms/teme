<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;

/**
 * `teme:docs:handbook-index`: writes `docs/guides/developers/llms.txt` from
 * the handbook pages' front matter, in the README's order.
 */
final class GenerateHandbookIndex extends Command
{
    /**
     * Pages under docs/guides/developers/, in the README's reading order.
     * The README itself is the index, not a listed entry. DeveloperGuide
     * and the handbook-index command share this list.
     *
     * @var list<string>
     */
    public const array PAGES = [
        'start/plugin-or-theme.md',
        'start/first-plugin.md',
        'start/first-theme.md',
        'start/development-site.md',
        'concepts/manifest.md',
        'concepts/hooks.md',
        'concepts/operations.md',
        'start/code-standards.md',
        'testing.md',
        'plugins/settings.md',
        'themes/regions.md',
        'rules/plugins-and-themes.md',
        'for-agents.md',
        'plugins/content-write.md',
        'plugins/jobs.md',
        'plugins/public-routes.md',
        'plugins/import.md',
        'plugins/insertions.md',
        'plugins/fediverse.md',
        'plugins/list-columns.md',
        'plugins/your-own-hooks.md',
        'plugins/notices.md',
        'themes/languages.md',
        'outside/management-api.md',
    ];

    /** @var string */
    protected $signature = 'teme:docs:handbook-index {--output= : Where to write llms.txt}';

    /** @var string */
    protected $description = 'Generate docs/guides/developers/llms.txt from the handbook pages';

    public function handle(): int
    {
        $lines = [
            '# Teme developer handbook',
            '',
            'Guides for people and agents who build Teme plugins and themes. Reference under docs/reference/ is generated; these pages are written and tested against the code.',
            '',
        ];

        foreach (self::PAGES as $name) {
            $path = base_path('docs/guides/developers/'.$name);
            $text = str_replace("\r\n", "\n", (string) file_get_contents($path));
            $front = $this->frontMatter($text);

            $lines[] = sprintf(
                '- [%s](%s): %s',
                (string) ($front['title'] ?? $name),
                $name,
                (string) ($front['summary'] ?? ''),
            );
        }

        $lines[] = '';

        $output = (string) ($this->option('output') ?: base_path('docs/guides/developers/llms.txt'));

        if (! is_dir(dirname($output))) {
            mkdir(dirname($output), 0755, true);
        }

        // "\n", not PHP_EOL: the same bytes on every platform (B-037).
        file_put_contents($output, implode("\n", $lines)."\n");

        $this->info("Wrote {$output}");

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function frontMatter(string $text): array
    {
        if (preg_match('/\A---\n(.*?)\n---\n/s', $text, $found) !== 1) {
            return [];
        }

        $parsed = Yaml::parse($found[1]);

        return is_array($parsed) ? $parsed : [];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Fixtures\Guides;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * One page of a written guide under docs/guides/, taken apart for the tests
 * that keep the guides honest: its front matter, headings, links, code, and
 * the names it sets in bold. It reads the files directly and never needs the
 * application, so a dataset can list pages before the application boots.
 */
final class GuideFile
{
    public function __construct(public readonly string $path) {}

    /** The repository's root folder. */
    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Every page of one audience's guide, by path.
     *
     * @return list<self>
     */
    public static function in(string $audience): array
    {
        $folder = self::root()."/docs/guides/{$audience}";

        if (! is_dir($folder)) {
            return [];
        }

        $found = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'md') {
                $found[] = new self("docs/guides/{$audience}/".str_replace('\\', '/', substr($file->getPathname(), strlen($folder) + 1)));
            }
        }

        usort($found, fn (self $one, self $other): int => $one->path <=> $other->path);

        return $found;
    }

    public function exists(): bool
    {
        return is_file(self::root().'/'.$this->path);
    }

    public function text(): string
    {
        $text = @file_get_contents(self::root().'/'.$this->path);

        if ($text === false) {
            throw new RuntimeException("{$this->path} isn't there.");
        }

        return str_replace("\r\n", "\n", $text);
    }

    /**
     * The YAML between the first two --- lines; empty when the page has none.
     *
     * @return array<string, mixed>
     */
    public function frontMatter(): array
    {
        if (preg_match('/\A---\n(.*?)\n---\n/s', $this->text(), $found) !== 1) {
            return [];
        }

        $parsed = Yaml::parse($found[1]);

        return is_array($parsed) ? $parsed : [];
    }

    /** Everything after the front matter. */
    public function body(): string
    {
        return (string) preg_replace('/\A---\n.*?\n---\n/s', '', $this->text(), 1);
    }

    /**
     * The body's lines outside fenced code, with inline code removed, for
     * reading prose: links, bold names and loose mentions.
     */
    public function prose(): string
    {
        $lines = [];
        $inside = false;

        foreach (explode("\n", $this->body()) as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                $inside = ! $inside;

                continue;
            }

            if (! $inside) {
                $lines[] = (string) preg_replace('/`[^`\n]+`/', '', $line);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Inline code, in the order it appears.
     *
     * @return list<string>
     */
    public function codeSpans(): array
    {
        $spans = [];
        $inside = false;

        foreach (explode("\n", $this->body()) as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                $inside = ! $inside;

                continue;
            }

            if (! $inside) {
                preg_match_all('/`([^`\n]+)`/', $line, $found);
                array_push($spans, ...$found[1]);
            }
        }

        return $spans;
    }

    /**
     * Fenced code blocks: the word after the opening fence, and what is in it.
     *
     * @return list<array{lang: string, body: string}>
     */
    public function fences(): array
    {
        preg_match_all('/^[ \t]*(?:```|~~~)([A-Za-z0-9_-]*)[^\n]*\n(.*?)^[ \t]*(?:```|~~~)[ \t]*$/ms', $this->body(), $found, PREG_SET_ORDER);

        return array_map(fn (array $fence): array => ['lang' => $fence[1], 'body' => $fence[2]], $found);
    }

    /**
     * Headings outside fenced code, as [level, text].
     *
     * @return list<array{0: int, 1: string}>
     */
    public function headings(): array
    {
        $headings = [];
        $inside = false;

        foreach (explode("\n", $this->body()) as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                $inside = ! $inside;

                continue;
            }

            if (! $inside && preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $found) === 1) {
                $headings[] = [strlen($found[1]), str_replace('`', '', $found[2])];
            }
        }

        return $headings;
    }

    /**
     * The text of the headings at one level.
     *
     * @return list<string>
     */
    public function headingsAt(int $level): array
    {
        return array_values(array_map(fn (array $heading): string => $heading[1], array_filter($this->headings(), fn (array $heading): bool => $heading[0] === $level)));
    }

    /**
     * The anchors a link may point at: each heading, the way GitHub makes it.
     *
     * @return list<string>
     */
    public function anchors(): array
    {
        return array_map(fn (array $heading): string => self::slug($heading[1]), $this->headings());
    }

    public static function slug(string $heading): string
    {
        $slug = mb_strtolower(trim(str_replace('*', '', $heading)));
        $slug = (string) preg_replace('/[^\p{L}\p{N}\s_-]/u', '', $slug);

        return (string) preg_replace('/\s/u', '-', $slug);
    }

    /**
     * Links outside code: [text](target).
     *
     * @return list<array{text: string, target: string}>
     */
    public function links(): array
    {
        preg_match_all('/(?<!!)\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', $this->prose(), $found, PREG_SET_ORDER);

        return array_map(fn (array $link): array => ['text' => $link[1], 'target' => $link[2]], $found);
    }

    /**
     * Names set in bold outside code: [**Publish**, **Settings › General**].
     *
     * @return list<string>
     */
    public function boldSpans(): array
    {
        preg_match_all('/\*\*(.+?)\*\*/', $this->prose(), $found);

        return $found[1];
    }

    /**
     * Where a link leads, as a repository path and an anchor, or null for an
     * address outside the repository (https, mailto).
     *
     * @return array{path: string, anchor: string}|null
     */
    public function resolve(string $target): ?array
    {
        if (preg_match('#^(https?:|mailto:)#', $target) === 1) {
            return null;
        }

        [$file, $anchor] = array_pad(explode('#', $target, 2), 2, '');
        $parts = $file === '' ? explode('/', $this->path) : array_merge(explode('/', dirname($this->path)), explode('/', $file));
        $resolved = [];

        foreach ($parts as $part) {
            if ($part === '..') {
                array_pop($resolved);
            } elseif ($part !== '.' && $part !== '') {
                $resolved[] = $part;
            }
        }

        return ['path' => implode('/', $resolved), 'anchor' => $anchor];
    }
}

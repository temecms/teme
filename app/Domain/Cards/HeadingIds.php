<?php

/**
 * The unique HTML ids every heading on a page gets while bodyHtml and regions
 * are drawn, and the outline (levels 2–4) a theme reads as `$page->headings`.
 * One instance is scoped per request so body and regions share the same used
 * set, seeded with landmark ids core and the default theme already write.
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards;

use Illuminate\Support\Str;
use WeakMap;

final class HeadingIds
{
    /**
     * Landmark ids written by core components and the default theme, which a
     * heading must not reuse (issue 369 follow-up).
     *
     * @var list<string>
     */
    public const array RESERVED = [
        'main',
        'content',
        'comments',
        'comments-h',
        'respond',
        'respond-h',
        'respond-errors',
        'page-h',
        'related-title',
        'echo-h',
        'c-keep',
        'c-subject',
        'search-results',
        'c-text',
        'c-text-hint',
        'c-text-error',
        'c-name',
        'c-name-hint',
        'c-name-error',
        'c-email',
        'c-email-hint',
        'c-email-error',
        'c-url',
        'c-url-hint',
        'c-url-error',
        'search-wide',
        'search-narrow',
        'search-notfound',
        'teme-password',
        'teme-password-error',
    ];

    /** @var array<string, true> */
    private array $used = [];

    /** @var list<array{level: int, text: string, id: string}> */
    private array $outline = [];

    /** @var WeakMap<object, string> tiptap-php calls renderHTML twice per node; the same object keeps one id */
    private WeakMap $forNode;

    public function __construct()
    {
        $this->forNode = new WeakMap;

        foreach (self::RESERVED as $id) {
            $this->used[$id] = true;
        }
    }

    /**
     * The id this heading takes: its permanent node id when present, else
     * from its words (or `heading` when the slug is empty), made unique on
     * the page with -2, -3 when needed.
     */
    public function assign(object $node, ?string $permanent, string $text, int $level): string
    {
        if (isset($this->forNode[$node])) {
            return $this->forNode[$node];
        }

        if (is_string($permanent) && $permanent !== '') {
            $base = $permanent;
        } else {
            $slug = Str::slug($text);
            $base = $slug !== '' ? $slug : 'heading';
        }

        $id = $base;
        $n = 2;

        // comment-<n> and author-<n> are the anchors the comments list writes, so a heading never takes one.
        while (isset($this->used[$id]) || preg_match('/^(?:comment|author)-\d+$/', $id) === 1) {
            $id = $base.'-'.$n;
            $n++;
        }

        $this->used[$id] = true;
        $this->forNode[$node] = $id;

        if ($level >= 2 && $level <= 4) {
            $this->outline[] = ['level' => $level, 'text' => $text, 'id' => $id];
        }

        return $id;
    }

    /**
     * Every level-2–4 heading drawn, in document order, each with its level,
     * plain text and id.
     *
     * @return list<array{level: int, text: string, id: string}>
     */
    public function outline(): array
    {
        return $this->outline;
    }
}

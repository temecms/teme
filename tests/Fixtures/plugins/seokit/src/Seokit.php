<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Seokit;

use Closure;
use Teme\Contracts\Editor\CheckedItem;
use Teme\Contracts\Editor\CheckResult;
use Teme\Contracts\Editor\HasEditorChecks;
use Teme\Contracts\Head\HasHeadMeta;
use Teme\Contracts\Head\HeadMeta;
use Teme\Contracts\Head\HeadPage;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;
use Teme\Contracts\Sitemaps\HasSitemaps;
use Teme\Contracts\Sitemaps\PluginSitemap;
use Teme\Contracts\StructuredData\GraphNode;
use Teme\Contracts\StructuredData\HasStructuredData;

/**
 * A fixture plugin that answers every SEO socket. The tests set what each
 * answers and read what each was asked.
 */
final class Seokit implements HasEditorChecks, HasHeadMeta, HasSitemaps, HasStructuredData, Plugin
{
    /** @var (Closure(CheckedItem): mixed)|null */
    public static ?Closure $checks = null;

    /** @var (Closure(HeadPage): mixed)|null */
    public static ?Closure $meta = null;

    /** @var (Closure(HeadPage): mixed)|null */
    public static ?Closure $nodes = null;

    /** @var list<CheckedItem> */
    public static array $checked = [];

    /** @var list<HeadPage> */
    public static array $pages = [];

    public static function reset(): void
    {
        self::$checks = null;
        self::$meta = null;
        self::$nodes = null;
        self::$checked = [];
        self::$pages = [];
        NewsSitemap::reset();
    }

    public function register(Hooks $hooks): void {}

    /**
     * @return list<CheckResult>
     */
    public function checkItem(CheckedItem $item): array
    {
        self::$checked[] = $item;

        /** @var list<CheckResult> */
        return self::$checks === null
            ? [new CheckResult('keyphrase', CheckResult::IMPROVE, 'Add a focus keyphrase.', ownField: 'focus')]
            : (self::$checks)($item);
    }

    /**
     * @return list<HeadMeta>
     */
    public function headMeta(HeadPage $page): array
    {
        self::$pages[] = $page;

        /** @var list<HeadMeta> */
        return self::$meta === null ? [HeadMeta::name('seokit', 'on')] : (self::$meta)($page);
    }

    /**
     * @return list<GraphNode>
     */
    public function graphNodes(HeadPage $page): array
    {
        /** @var list<GraphNode> */
        return self::$nodes === null ? [] : (self::$nodes)($page);
    }

    /**
     * @return array<string, PluginSitemap>
     */
    public function sitemaps(): array
    {
        return ['news' => new NewsSitemap];
    }
}

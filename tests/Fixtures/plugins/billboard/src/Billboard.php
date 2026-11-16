<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Billboard;

use RuntimeException;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Insertions\InlineScripts;
use Teme\Contracts\Insertions\PageFacts;
use Teme\Contracts\Insertions\PageInsertion;
use Teme\Contracts\Insertions\PageInsertionContributor;
use Teme\Contracts\Insertions\PagePosition;
use Teme\Contracts\Plugins\Plugin;

/**
 * Places marked markup on every page, at every page position, and notes the
 * facts it was asked with. A test may give it other insertions, make it
 * throw, or reach its InlineScripts door.
 */
final class Billboard implements PageInsertionContributor, Plugin
{
    /** The inline script the fixture's bottom of the page carries. */
    public const string SCRIPT = '(window.billboard = window.billboard || []).push({});';

    /** @var list<PageFacts> the facts it was asked with, in order */
    public static array $asked = [];

    /** @var list<PageInsertion>|null what it gives in place of its own; null for its own */
    public static ?array $gives = null;

    /** Whether it throws when asked. */
    public static bool $throws = false;

    public static ?InlineScripts $scripts = null;

    public function __construct(InlineScripts $scripts)
    {
        self::$scripts = $scripts;
    }

    public function register(Hooks $hooks): void
    {
        //
    }

    public function pageInsertions(PageFacts $page): array
    {
        self::$asked[] = $page;

        if (self::$throws) {
            throw new RuntimeException('The billboard fell over.');
        }

        return self::$gives ?? [
            new PageInsertion('billboard.head', PagePosition::head(), '<script async src="https://ads.billboard.example/tag.js" crossorigin="anonymous"></script><meta name="google-adsense-account" content="ca-pub-1"><meta name="robots" content="noindex"><link rel="stylesheet" href="https://ads.billboard.example/a.css"><script>alert(1)</script><iframe src="https://frames.billboard.example/x"></iframe>'),
            new PageInsertion('billboard.top', PagePosition::bodyStart(), '<div data-test="billboard-top" onclick="alert(1)">Top</div>'),
            new PageInsertion('billboard.bottom', PagePosition::bodyEnd(), '<div data-test="billboard-bottom">Bottom</div><script>'.self::SCRIPT.'</script>'),
            new PageInsertion('billboard.after-3', PagePosition::afterItem(3), '<div data-test="billboard-after-3">After three</div>'),
            new PageInsertion('billboard.every-4', PagePosition::everyItems(4, 3), '<div data-test="billboard-every-4">Every four</div>'),
            new PageInsertion('billboard.before-region', PagePosition::region('after-content', 'before'), '<div data-test="billboard-before-region">Before</div>'),
            new PageInsertion('billboard.after-region', PagePosition::region('after-content'), '<div data-test="billboard-after-region">After</div>'),
            new PageInsertion('billboard.undeclared', PagePosition::region('nowhere'), '<div data-test="billboard-nowhere">Nowhere</div>'),
            new PageInsertion('billboard.bar', PagePosition::fixed('bottom'), '<div data-test="billboard-bar">Bar</div>'),
            new PageInsertion('billboard.second-bar', PagePosition::fixed('bottom'), '<div data-test="billboard-second-bar">Second</div>'),
            new PageInsertion('billboard.rail', PagePosition::fixed('right', 300), '<div data-test="billboard-rail">Rail</div>'),
        ];
    }

    /** Puts every static but its door back as it was. */
    public static function reset(): void
    {
        self::$asked = [];
        self::$gives = null;
        self::$throws = false;
    }
}

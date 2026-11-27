<?php

/**
 * The page insertions every contributor gives one public page: asked once,
 * only for a page PublicController started with its facts, the started
 * plugins granted pages.insert in plugin code order, each key's first kept.
 * Each contributor has a time budget, and so has the page; one that throws
 * or runs over is recorded as its plugin's fault and skipped (I-3353).
 * What is drawn is cleaned by PluginMarkup, and its origins, approved scripts and
 * declared hosts join the page's policy.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Insertions;

use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Hooks\ListenerFaults;
use App\Domain\Plugins\Columns\ColumnClock;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\Styles\PluginStyleUsage;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Themes\ComposerRegistry;
use App\Domain\Themes\ViewIntent;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Teme\Contracts\Insertions\ArticleFacts;
use Teme\Contracts\Insertions\PageFacts;
use Teme\Contracts\Insertions\PageInsertion;
use Teme\Contracts\Insertions\PageInsertionContributor;
use Teme\Contracts\Insertions\PagePosition;
use Teme\Contracts\Plugins\Capability;
use Throwable;

final class PageInsertions
{
    /** The longest one contributor may take, in milliseconds. */
    public const int CALL_BUDGET_MS = 100;

    /** The longest every contributor together may take on a page, in milliseconds. */
    public const int PAGE_BUDGET_MS = 250;

    /** The page kinds insertions are asked for, by the kind of template that draws them. */
    private const array KINDS = ['single' => 'article', 'latest' => 'latest', 'archive' => 'archive', 'taxonomy' => 'term', 'author' => 'author', 'date' => 'date', 'search' => 'search'];

    /** The request this page's facts belong to; another request is never answered with them. */
    private ?Request $request = null;

    private ?PageFacts $facts = null;

    /** @var array{0: Request, 1: ArticleFacts}|null the article this request built, with its facts */
    private ?array $article = null;

    /** @var array{0: Request, 1: string}|null the person the author archive this request built lists */
    private ?array $author = null;

    /** @var list<array{owner: string, code: string|null, insertion: PageInsertion}>|null */
    private ?array $given = null;

    /** @var list<array{string, string|null, PageInsertionContributor}> owner, code, contributor */
    private array $bound = [];

    /** @var array<string, true> the plugins whose declared hosts already joined the page's policy */
    private array $hosted = [];

    public function __construct(
        private readonly Container $container,
        private readonly LoadedPlugins $loaded,
        private readonly PluginRecords $records,
        private readonly ColumnClock $clock,
    ) {}

    /**
     * Notes the facts of the article this request built, as ArticleViews
     * asked its contributors: only an article that draws insertions has them.
     */
    public function article(ArticleFacts $facts): void
    {
        $this->article = [request(), $facts];
    }

    /**
     * Notes the person an author archive this request built lists, as
     * ListingViews found them: their ID as an article's facts give it.
     */
    public function author(string $id): void
    {
        $this->author = [request(), $id];
    }

    /**
     * Starts this request's page with what PathResolver answered: its
     * facts when it is a public page insertions are asked on, else none.
     * A page with facts carries the page-insertions tag, drawn or not.
     *
     * @param  array{kind: string, intent?: ViewIntent, model?: object, pageNumber?: int, private?: bool}  $answer
     */
    public function start(Request $request, array $answer, string $language): void
    {
        $this->request = $request;
        $this->given = null;
        $this->hosted = [];
        $this->facts = $this->factsFor($request, $answer, $language);

        if ($this->facts !== null) {
            $this->container->make(RenderRecord::class)->tag(PageTags::PAGE_INSERTIONS);
        }
    }

    /**
     * This page's facts, or null on a page insertions are never asked on.
     */
    public function facts(): ?PageFacts
    {
        if ($this->facts === null || $this->request !== request()) {
            return null;
        }

        // An error page drawn after the page began, as a template that failed: never on it.
        $kind = $this->container->make(ComposerRegistry::class)->intent()?->kind;

        return $kind !== null && isset(self::KINDS[$kind]) ? $this->facts : null;
    }

    /**
     * Registers this contributor for this scope when it was granted
     * pages.insert; otherwise leaves it unbound so it is never asked.
     *
     * @param  list<string>  $capabilities
     * @param  string|null  $owner  the plugin its faults are recorded under; its class when none
     * @param  string|null  $code  the plugin's code, whose approved scripts and declared hosts it may use
     */
    public function bind(PageInsertionContributor $contributor, array $capabilities, ?string $owner = null, ?string $code = null): void
    {
        if (! self::granted($capabilities)) {
            return;
        }

        $this->bound[] = [$owner ?? $contributor::class, $code, $contributor];
        $this->given = null;
    }

    /**
     * This page's insertions at these kinds of position, in the order they
     * were read, each key once; none on a page they are never asked on.
     *
     * @return list<array{owner: string, code: string|null, insertion: PageInsertion}>
     */
    public function at(string ...$kinds): array
    {
        return array_values(array_filter($this->given(), fn (array $entry): bool => in_array($entry['insertion']->at->kind, $kinds, true)));
    }

    /**
     * One insertion's markup, cleaned, its origins, its plugin's approved
     * scripts and its declared hosts noted in the page's policy. The head
     * keeps less than the body does.
     *
     * @param  array{owner: string, code: string|null, insertion: PageInsertion}  $entry
     */
    public function draw(array $entry, bool $head = false): string
    {
        $code = $entry['code'];
        $hosts = $code !== null && ! isset($this->hosted[$code]);
        $html = $this->container->make(PluginMarkup::class)->clean($code, $entry['insertion']->html, $head, $hosts);

        if ($hosts && trim($html) !== '') {
            $this->hosted[$code] = true;
        }

        $id = $code === null ? null : $this->insertionId($code, $entry['insertion']->key);

        if ($id !== null && trim($html) !== '') {
            $this->container->make(PluginStyleUsage::class)->record($code, 'insertion:'.$id);
        }

        return $html;
    }

    /**
     * The id a plugin's insertion is declared under in its styles: the part of its key after
     * the plugin's code and a dot, or null when the key doesn't start that way.
     */
    private function insertionId(string $code, string $key): ?string
    {
        $prefix = $code.'.';

        return str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : null;
    }

    /**
     * Every contributor's insertions for this page, asked once.
     *
     * @return list<array{owner: string, code: string|null, insertion: PageInsertion}>
     */
    private function given(): array
    {
        $facts = $this->facts();

        if ($facts === null) {
            return [];
        }

        if ($this->given !== null) {
            return $this->given;
        }

        $kept = [];
        $spent = 0;

        foreach ([...$this->plugins(), ...$this->bound] as [$owner, $code, $contributor]) {
            if ($spent >= self::PAGE_BUDGET_MS * 1_000_000) {
                $this->fault($owner, new ContributorTooSlow($owner, intdiv($spent, 1_000_000), self::PAGE_BUDGET_MS));

                continue;
            }

            $started = $this->clock->nanoseconds();

            try {
                $given = $contributor->pageInsertions($facts);
            } catch (Throwable $failure) {
                $spent += $this->clock->nanoseconds() - $started;
                $this->fault($owner, $failure);

                continue;
            }

            $took = $this->clock->nanoseconds() - $started;
            $spent += $took;

            if ($took > self::CALL_BUDGET_MS * 1_000_000) {
                $this->fault($owner, new ContributorTooSlow($owner, intdiv($took, 1_000_000), self::CALL_BUDGET_MS));

                continue;
            }

            foreach (self::onlyInsertions($given) as $insertion) {
                $kept[$insertion->key] ??= ['owner' => $owner, 'code' => $code, 'insertion' => $insertion];
            }
        }

        return $this->given = array_values($kept);
    }

    /**
     * The facts of the page PathResolver answered, or null for a page
     * insertions are never asked on: a redirect, a page not found, a
     * protected item, a comments page, or an article that draws none.
     *
     * @param  array{kind: string, intent?: ViewIntent, model?: object, pageNumber?: int, private?: bool}  $answer
     */
    private function factsFor(Request $request, array $answer, string $language): ?PageFacts
    {
        $intent = $answer['intent'] ?? null;

        if ($answer['kind'] !== 'view' || ($answer['private'] ?? false) || ! $intent instanceof ViewIntent || ! isset(self::KINDS[$intent->kind])) {
            return null;
        }

        $kind = $intent->isFront && in_array($intent->kind, ['single', 'latest'], true) ? 'home' : self::KINDS[$intent->kind];
        $path = '/'.ltrim($request->getPathInfo(), '/');
        $page = $answer['pageNumber'] ?? 1;

        if ($intent->kind === 'single') {
            $article = $this->article !== null && $this->article[0] === $request ? $this->article[1] : null;
            $id = is_object($answer['model'] ?? null) && property_exists($answer['model'], 'id') ? (string) $answer['model']->id : null;

            // No facts: the item has "No inserted content" on, or wasn't drawn for the public.
            if ($article === null || $article->contentId !== $id) {
                return null;
            }

            return new PageFacts($kind, $path, $language, $article->type, $article->contentId, $article->termIdsByTaxonomy, $article->authorIds, 1);
        }

        $terms = [];

        if ($intent->kind === 'taxonomy' && $intent->taxonomy !== null && $intent->term !== null) {
            $id = Term::query()->where('taxonomy', $intent->taxonomy)->where('slug', $intent->term)->value('id');
            $terms = $id === null ? [] : [$intent->taxonomy => [(string) $id]];
        }

        $authors = $intent->kind === 'author' && $this->author !== null && $this->author[0] === $request ? [$this->author[1]] : [];

        return new PageFacts($kind, $path, $language, $intent->type, null, $terms, $authors, $page);
    }

    /**
     * The started plugins that give page insertions and whose enabled row
     * grants pages.insert, in plugin code order.
     *
     * @return list<array{string, string|null, PageInsertionContributor}>
     */
    private function plugins(): array
    {
        $running = array_filter($this->loaded->running(), fn (object $plugin): bool => $plugin instanceof PageInsertionContributor);

        if ($running === []) {
            return [];
        }

        $contributors = [];

        foreach ($this->records->enabled() as $record) {
            $plugin = $running[$record->name] ?? null;

            if ($plugin instanceof PageInsertionContributor && self::granted($record->consent['capabilities'] ?? [])) {
                $contributors[(string) $record->code] = [(string) $record->name, (string) $record->code, $plugin];
            }
        }

        ksort($contributors, SORT_STRING);

        return array_values($contributors);
    }

    /**
     * What a contributor gave, without anything that isn't a PageInsertion:
     * PHP checks only that its answer is an array, not what the array holds.
     *
     * @return list<PageInsertion>
     */
    private static function onlyInsertions(mixed $given): array
    {
        return is_array($given) ? array_values(array_filter($given, fn (mixed $insertion): bool => $insertion instanceof PageInsertion)) : [];
    }

    private function fault(string $owner, Throwable $failure): void
    {
        $this->container->make(ListenerFaults::class)->record($owner, $owner.'#page-insertions', PageInsertionContributor::class, $failure);
    }

    /** @param  array<mixed>  $capabilities */
    private static function granted(array $capabilities): bool
    {
        return in_array(Capability::PagesInsert->value, $capabilities, true);
    }

    /**
     * Whether this position is drawn between a list's items after item $n,
     * the last item never.
     */
    public static function afterItem(PagePosition $at, int $n, bool $last): bool
    {
        if ($last || $n < 1) {
            return false;
        }

        return match ($at->kind) {
            PagePosition::AFTER_ITEM => ($at->args['n'] ?? 0) === $n,
            PagePosition::EVERY_ITEMS => ($step = $at->args['n'] ?? 0) > 0 && $n % $step === 0 && intdiv($n, $step) <= min($at->args['max'] ?? PagePosition::EVERY_ITEMS_MAX, PagePosition::EVERY_ITEMS_MAX),
            default => false,
        };
    }
}

<?php

/**
 * The old site's redirect rules (I-297): each store the companion reads, in
 * order, a rule at a time through ImportOldRedirect, with its own map row; a
 * rule unchanged since it last came writes nothing. Fifty seconds a job,
 * carried on from the last rule done.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Redirects;

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Operations\ClearRunMessages;
use App\Domain\Import\Operations\ImportOldRedirect;
use App\Domain\Import\Operations\Input\ClearRunMessagesInput;
use App\Domain\Import\Operations\Input\ImportOldRedirectInput;
use App\Domain\Import\Phases\Addresses\OldSiteUrls;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Import\Source\SourceReader;
use Illuminate\Support\Str;

final readonly class RedirectsStep implements StepPhase
{
    /** Seconds a job reads for before it hands on to the next. */
    public const int BUDGET = 50;

    /** Rules a page of a paged store. */
    public const int PAGE = 500;

    public function __construct(private ImportMap $map) {}

    public function key(): string
    {
        return 'redirects';
    }

    public function order(): int
    {
        return 900;
    }

    public function label(): string
    {
        return 'Redirects';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        // An export file has none of this (docs/architecture/import.md).
        if ($context->readsFile()) {
            return StepResult::done();
        }

        if ($cursor === null) {
            app(ClearRunMessages::class)->execute(new ClearRunMessagesInput($context->runId, 'redirects.', $context->actorId()));
        }

        [$store, $after] = self::position($cursor);
        $targets = new RuleTargets($context->sourceId, OldSiteUrls::hostOf($context->sourceId));
        $started = microtime(true);
        $messages = [];

        while ($store !== null) {
            ['rules' => $rules, 'next' => $next, 'unreadable' => $unreadable] = $this->read($reader, $store, $after);

            if ($unreadable) {
                $name = OldRules::NAMES[$store];
                $messages[] = new ImportMessage('redirects.unreadable', "The old site’s {$name} rules couldn’t be read, so none of them came across. Add them in Tools › Redirects.", ['store' => $store]);
            }

            foreach ($rules as ['key' => $key, 'item' => $item, 'done' => $done]) {
                $this->import($store, $item, $key, $context, $targets);

                if (microtime(true) - $started >= self::BUDGET) {
                    return StepResult::continueAt("{$store}:{$done}", $messages);
                }
            }

            [$store, $after] = $next;
        }

        return StepResult::done($messages);
    }

    /**
     * One rule, read, its targets resolved, and imported unless unchanged.
     */
    private function import(string $store, mixed $item, int|string $key, ImportContext $context, RuleTargets $targets): void
    {
        $rule = OldRules::read($store, $item, $key);

        if ($this->map->entry($context->sourceId, $rule->ref)?->source_hash === $rule->hash) {
            return;
        }

        $redirects = [];
        $notes = $rule->notes;

        foreach ($rule->redirects as $redirect) {
            $resolved = ['from' => $redirect['from'], ...$targets->resolve($redirect['to']), 'status' => $redirect['status']];

            // The old site's own address, or a wildcard, made only a placeholder here: refused as RegexPaths refuses one.
            if ($resolved['contentId'] === null && $resolved['mediaId'] === null && RegexPaths::onlyPlaceholder($resolved['to'])) {
                $said = Str::limit($redirect['from'], 300);
                // First, so a rule left with no redirect says this first.
                $notes = [new ImportMessage('redirect.regex', "Its pattern {$said} can’t be written as Teme’s, because ".RegexPaths::ANYWHERE.'. Add it in Tools › Redirects, with [name] for one part of an address and [...rest] for the rest.', ['pattern' => mb_substr($redirect['from'], 0, 500)]), ...$notes];

                continue;
            }

            $redirects[] = $resolved;
        }

        app(ImportOldRedirect::class)->execute(new ImportOldRedirectInput($context->sourceId, $context->runId, $rule->ref, $rule->hash, $redirects, $notes, $context->actorId()));
    }

    /**
     * A store's rules from $after: a page of a paged store, by ID, or a
     * whole store's entries past the $after done; each with where a job
     * stopping after it carries on; the store and place after them; and
     * whether the store's answer couldn't be read.
     *
     * @return array{rules: list<array{key: int|string, item: mixed, done: int}>, next: array{0: string|null, 1: int}, unreadable: bool}
     */
    private function read(SourceReader $reader, string $store, int $after): array
    {
        $following = [self::following($store), 0];

        if (OldRules::STORES[$store]) {
            $page = $reader->page('redirects', ['source' => $store], $after, self::PAGE);
            $rules = [];

            foreach (array_values($page->items) as $index => $item) {
                $rules[] = ['key' => $index, 'item' => $item, 'done' => (int) ($item['id'] ?? 0)];
            }

            return ['rules' => $rules, 'next' => $page->nextAfterId === null ? $following : [$store, $page->nextAfterId], 'unreadable' => false];
        }

        $value = $reader->whole('redirects', ['source' => $store])[$store === 'yoast_premium' ? 'plain' : 'rules'] ?? null;

        if (! is_array($value)) {
            return ['rules' => [], 'next' => $following, 'unreadable' => $value !== null];
        }

        $rules = [];
        $index = 0;

        foreach ($value as $key => $item) {
            $index++;

            if ($index > $after) {
                $rules[] = ['key' => $key, 'item' => $item, 'done' => $index];
            }
        }

        return ['rules' => $rules, 'next' => $following, 'unreadable' => false];
    }

    /**
     * Where the cursor stands: the store, and the rule done last there.
     *
     * @return array{0: string|null, 1: int}
     */
    private static function position(?string $cursor): array
    {
        if ($cursor !== null && preg_match('/^([a-z0-9_]+):(\d{1,18})$/', $cursor, $match) === 1 && isset(OldRules::STORES[$match[1]])) {
            return [$match[1], (int) $match[2]];
        }

        return [array_key_first(OldRules::STORES), 0];
    }

    /**
     * The store read after this one, or null after the last.
     */
    private static function following(string $store): ?string
    {
        $stores = array_keys(OldRules::STORES);
        $index = array_search($store, $stores, true);

        return $index === false ? null : ($stores[$index + 1] ?? null);
    }
}

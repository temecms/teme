<?php

declare(strict_types=1);

namespace App\Domain\Addresses\Jobs;

use App\Domain\Addresses\Operations\Input\RebuildPathsInput;
use App\Domain\Addresses\Operations\RebuildPaths;
use App\Domain\Support\Security\JobActor;
use App\Domain\Taxonomy\Queries\TermTree;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Moves every address to where the settings now put it, 500 targets a run:
 * content, then terms, then authors, each run queueing the next from the
 * last ID. With a term, only the items filed under it or beneath it; with
 * a language, only its items and terms, whose prefix moved (I-3360). It
 * carries the person who changed things (I-392), and RebuildPaths
 * authorizes them. Progress is kept in the cache for Settings › Addresses.
 */
final class RecomputeAddresses implements ShouldQueue
{
    use Queueable;

    /** The cache key Settings › Addresses polls: done, total, running. */
    public const string PROGRESS = 'addresses.recompute';

    /** One run at a time, as WithoutOverlapping would, but released before the next is queued. */
    public const string LOCK = 'addresses-recompute';

    /** The kinds of Paths::KINDS with targets to rebuild; a type's archive page follows its setting. */
    private const array ORDER = ['content', 'term', 'user', 'person'];

    public function __construct(
        public readonly JobActor $actor,
        public readonly ?string $termId = null,
        public readonly string $afterId = '',
        public readonly string $kind = 'content',
        public readonly ?string $language = null,
    ) {}

    public function handle(): void
    {
        $this->actor->bind();

        $next = null;

        // The next run is queued only once this one has let go of the lock,
        // so a synchronous queue, which runs it at once, is not refused.
        $ran = Cache::lock(self::LOCK, 300)->get(function () use (&$next): void {
            $next = $this->runBatch();
        });

        if ($ran === false) {
            $this->release(10);

            return;
        }

        if ($next !== null) {
            dispatch($next);
        }
    }

    /**
     * Rebuilds one batch and says what comes after it, or null when done.
     */
    private function runBatch(): ?self
    {
        if ($this->kind === 'content' && $this->afterId === '') {
            Cache::put(self::PROGRESS, ['done' => 0, 'total' => $this->total(), 'running' => true], now()->addDay());
        }

        $ids = $this->nextIds();

        if ($ids !== []) {
            app(RebuildPaths::class)->execute(new RebuildPathsInput($this->kind, $ids, $this->actor->userId));

            $progress = (array) Cache::get(self::PROGRESS, ['done' => 0, 'total' => 0, 'running' => true]);
            Cache::put(self::PROGRESS, [...$progress, 'done' => (int) ($progress['done'] ?? 0) + count($ids)], now()->addDay());
        }

        if (count($ids) === RebuildPaths::MOST) {
            return new self($this->actor, $this->termId, (string) end($ids), $this->kind, $this->language);
        }

        $following = $this->termId === null ? (self::ORDER[array_search($this->kind, self::ORDER, true) + 1] ?? null) : null;

        // A language's run stops after its terms: of Paths::KINDS, people have no language.
        if ($following !== null && ! ($this->language !== null && $following === 'user')) {
            return new self($this->actor, null, '', $following, $this->language);
        }

        $progress = (array) Cache::get(self::PROGRESS, []);
        Cache::put(self::PROGRESS, [...$progress, 'running' => false], now()->addDay());

        return null;
    }

    /**
     * The next IDs of this kind after the last one done; none for a kind
     * this release doesn't know.
     *
     * @return list<string>
     */
    private function nextIds(): array
    {
        // By kind of ORDER (Paths::KINDS).
        $query = match ($this->kind) {
            'content' => $this->contentQuery()->when($this->afterId !== '', fn ($q) => $q->where('contents.id', '>', $this->afterId))->orderBy('contents.id')->select('contents.id'),
            'term' => $this->termQuery()->when($this->afterId !== '', fn ($q) => $q->where('id', '>', $this->afterId))->orderBy('id')->select('id'),
            'user' => DB::table('users')->where('id', '>', (int) $this->afterId)->orderBy('id')->select('id'),
            // A person with no account has a page of their own; an account's person is its account's (I-3366).
            'person' => DB::table('people')->whereNull('user_id')->when($this->afterId !== '', fn ($q) => $q->where('id', '>', $this->afterId))->orderBy('id')->select('id'),
            // A kind this release doesn't know has no targets here.
            default => null,
        };

        return $query === null ? [] : $query->limit(RebuildPaths::MOST)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
    }

    /**
     * Every item, or with a term only those filed under it or beneath it.
     */
    private function contentQuery(): Builder
    {
        $query = DB::table('contents');

        if ($this->language !== null) {
            $query->where('language', $this->language);
        }

        if ($this->termId !== null) {
            $query->whereExists(fn ($sub) => $sub->selectRaw('1')->from('content_terms')
                ->whereColumn('content_terms.content_id', 'contents.id')
                ->where('content_terms.scope', 'published')
                ->whereIn('content_terms.term_id', app(TermTree::class)->descendantIds($this->termId)));
        }

        return $query;
    }

    /**
     * Every term, or a language's only.
     */
    private function termQuery(): Builder
    {
        return DB::table('terms')->when($this->language !== null, fn ($q) => $q->where('language', $this->language));
    }

    /**
     * How many targets the whole recompute will visit.
     */
    private function total(): int
    {
        $content = $this->contentQuery()->count();

        if ($this->termId !== null) {
            return $content;
        }

        return $content + $this->termQuery()->count() + ($this->language === null ? DB::table('users')->count() + DB::table('people')->whereNull('user_id')->count() : 0);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Cache\PageTags;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\ReindexSearchInput;
use App\Domain\Contents\SearchText;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Site\SiteLanguage;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use App\Domain\Types\TypeAccess;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Operations\OperationInput;

/**
 * Rebuilds the search index of up to 500 published items whose search was
 * built with other rules than their own language's (I-435, I-3360), in one
 * transaction, and says how many remain. ReindexSearchJob runs it until
 * none do. An administrator's change of language asks for it, and a change
 * to a type's searchable fields.
 */
final class ReindexSearch extends BaseOperation
{
    /** The most items one batch rebuilds. */
    public const int BATCH = 500;

    protected function authorize(OperationInput $input): void
    {
        /** @var ReindexSearchInput $input */
        if ($input->actorId === null) {
            if (Operator::isPresent()) {
                return;
            }

            throw new AuthorizationException('Not permitted to rebuild search.');
        }

        $actor = User::query()->find($input->actorId);

        // A change to a type's searchable fields rebuilds its items' search too.
        if ($actor === null || ! ($actor->can('settings.general.manage') || $actor->can(TypeAccess::MANAGE))) {
            throw new AuthorizationException('Not permitted to rebuild search.');
        }
    }

    protected function validate(OperationInput $input): void {}

    /**
     * @return array{reindexed: int, remaining: int}
     */
    protected function perform(OperationInput $input): array
    {
        $ids = self::waiting()->orderBy('id')->limit(self::BATCH)->pluck('id')->all();

        foreach (Content::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get() as $content) {
            SearchText::sync($content);

            // A search lists what its types have published (I-263).
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::CONTENT, (string) $content->id, [PageTags::listOf((string) $content->type)]));
        }

        return ['reindexed' => count($ids), 'remaining' => self::waiting()->count()];
    }

    /**
     * The published, live items whose search was built with other rules than
     * their own language's (I-3360).
     */
    private static function waiting(): Builder
    {
        $languages = app(SiteLanguages::class);
        $rules = app(SiteLanguage::class);

        return DB::table('contents')
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->whereNotNull('published_revision_id')
            ->where(function (Builder $query) use ($languages, $rules): void {
                $default = $rules->searchConfig($languages->defaultTag());
                $query->where(fn (Builder $query) => $query->whereNull('language')
                    ->where(fn (Builder $query) => $query->whereNull('search_config')->orWhere('search_config', '<>', $default)));

                foreach ($languages->others() as $tag) {
                    $config = $rules->searchConfig($tag);
                    $query->orWhere(fn (Builder $query) => $query->where('language', $tag)
                        ->where(fn (Builder $query) => $query->whereNull('search_config')->orWhere('search_config', '<>', $config)));
                }
            });
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Sockets;

use App\Domain\Fediverse\FediverseSettingsGroup;
use App\Domain\Fediverse\Models\FediverseActor;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Fediverse\FediverseFollowers;

/**
 * A plugin's read-only door to who follows the site's accounts, given with
 * fediverse.read.
 */
final readonly class PluginFollowers implements FediverseFollowers
{
    /**
     * The addresses of the enabled blog and author accounts that can be followed now.
     *
     * @return list<string>
     */
    public function actors(): array
    {
        $kinds = array_values(array_filter([
            FediverseSettingsGroup::blogFollowable() ? FediverseActor::BLOG : null,
            FediverseSettingsGroup::authorsFollowable() ? FediverseActor::PERSON : null,
        ]));

        /** @var list<string> */
        return FediverseActor::query()->where('enabled', true)->whereIn('kind', $kinds)->orderBy('created_at')->orderBy('id')->pluck('uri')->all();
    }

    /**
     * How many accounts follow this one of the site's.
     */
    public function count(string $actorUri): int
    {
        return $this->following($actorUri)->count();
    }

    /**
     * The addresses of the accounts that follow this one of the site's, oldest first.
     *
     * @return list<string>
     */
    public function followers(string $actorUri, int $limit = 100, int $offset = 0): array
    {
        /** @var list<string> */
        return $this->following($actorUri)
            ->orderBy('f.since')->orderBy('r.uri')
            ->offset(max(0, $offset))->limit(max(0, min($limit, 1000)))
            ->pluck('r.uri')->map(fn (mixed $uri): string => (string) $uri)->all();
    }

    private function following(string $actorUri): Builder
    {
        return DB::table('fediverse_followers as f')
            ->join('fediverse_actors as a', 'a.id', '=', 'f.local_actor_id')
            ->join('fediverse_remote_actors as r', 'r.id', '=', 'f.remote_actor_id')
            ->where('a.uri', $actorUri)
            ->where('a.enabled', true)
            ->whereNull('r.gone_at');
    }
}

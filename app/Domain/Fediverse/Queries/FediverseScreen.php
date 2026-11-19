<?php

/**
 * Settings › Fediverse's data, as the maintainers approved its drawing: the
 * switch and who can be followed, then the accounts, the servers not
 * answering, the blocked servers, and what the import brought.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Queries;

use App\Domain\Fediverse\ActivityPub;
use App\Domain\Fediverse\FediverseSettingsGroup;
use App\Domain\Fediverse\FediverseSwitch;
use App\Domain\Fediverse\Models\BlockedHost;
use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Settings\Modules;
use App\Domain\Settings\Queries\SettingsForm;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Teme\Contracts\Settings\SettingsReader;

final readonly class FediverseScreen
{
    /** The most rows the servers-not-answering table shows. */
    public const int UNREACHABLE = 50;

    public function __construct(private SettingsForm $form, private FediverseAuthors $authors) {}

    /**
     * Settings › Fediverse as its page needs it: the switch with whether
     * this person may change it; the fediverse group's fields, values and
     * version; the blog's account and each author's (every person while
     * authors can be followed, else only those with an account), each
     * with its handle and followers; the hosts not answering, by host, with
     * the accounts failing there and the deliveries that failed, blocked
     * hosts left out; the blocked hosts by name; and the accounts and
     * followers imported from WordPress.
     *
     * @return array{
     *     description: string,
     *     fediverse: array{field: array<string, mixed>, value: string, version: int, canSwitch: bool},
     *     fields: list<array<string, mixed>>,
     *     values: array<string, mixed>,
     *     version: int,
     *     accounts: list<array{kind: string, personId: string|null, name: string, handle: string, followers: int, enabled: bool}>,
     *     unreachable: list<array{host: string, accounts: int, failed: int}>,
     *     blocked: list<array{host: string, reason: string|null, since: string}>,
     *     imported: array{accounts: int, followers: int}
     * }
     */
    public function data(User $viewer): array
    {
        $modules = $this->form->find('modules') ?? throw new RuntimeException('No settings group [modules] is registered.');
        $group = $this->form->find(FediverseSettingsGroup::KEY) ?? throw new RuntimeException('No settings group [fediverse] is registered.');
        $switch = array_values(array_filter($modules['fields'], fn (array $field): bool => $field['key'] === Modules::FEDIVERSE))[0]
            ?? throw new RuntimeException('The modules group has no fediverse switch.');

        return [
            'description' => $group['group']['description'],
            'fediverse' => [
                'field' => $switch,
                'value' => FediverseSwitch::on() ? Modules::ON : Modules::OFF,
                'version' => $modules['version'],
                'canSwitch' => $viewer->can('settings.modules.manage'),
            ],
            'fields' => $group['fields'],
            'values' => $group['values'],
            'version' => $group['version'],
            'accounts' => $this->accounts(),
            'unreachable' => $this->unreachable(),
            'blocked' => BlockedHost::query()->orderBy('host')->get()
                ->map(fn (BlockedHost $blocked): array => ['host' => $blocked->host, 'reason' => $blocked->reason, 'since' => $blocked->created_at->toIso8601String()])
                ->all(),
            'imported' => $this->imported(),
        ];
    }

    /**
     * @return list<array{kind: string, personId: string|null, name: string, handle: string, followers: int, enabled: bool}>
     */
    private function accounts(): array
    {
        $host = FediverseDocuments::host();
        $followers = DB::table('fediverse_followers')->groupBy('local_actor_id')->selectRaw('local_actor_id, count(*) as total')->pluck('total', 'local_actor_id');
        $actors = FediverseActor::query()->where('kind', '!=', FediverseActor::APPLICATION)->orderBy('created_at')->get();
        $rows = [];

        foreach ($actors->where('kind', FediverseActor::BLOG) as $blog) {
            $rows[] = [
                'kind' => FediverseActor::BLOG,
                'personId' => null,
                'name' => app(SettingsReader::class)->string('general', 'site_title'),
                'handle' => '@'.$blog->username.'@'.$host,
                'followers' => (int) ($followers[$blog->id] ?? 0),
                'enabled' => $blog->enabled,
            ];
        }

        $byPerson = $actors->where('kind', FediverseActor::PERSON)->keyBy('person_id');
        $all = FediverseSettingsGroup::authorsFollowable();

        foreach ($this->authors->all() as $author) {
            $actor = $byPerson->get($author->id);

            if ($actor === null && ! $all) {
                continue;
            }

            $rows[] = [
                'kind' => FediverseActor::PERSON,
                'personId' => $author->id,
                'name' => $author->name,
                'handle' => '@'.($actor->username ?? $author->slug).'@'.$host,
                'followers' => $actor === null ? 0 : (int) ($followers[$actor->id] ?? 0),
                'enabled' => $actor !== null && $actor->enabled,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{host: string, accounts: int, failed: int}>
     */
    private function unreachable(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT f.host, sum(f.accounts)::int AS accounts, sum(f.failed)::int AS failed FROM (
                SELECT host, count(*) AS accounts, 0 AS failed FROM fediverse_remote_actors
                    WHERE failing_since IS NOT NULL AND gone_at IS NULL GROUP BY host
                UNION ALL
                SELECT host, 0, count(*) FROM fediverse_deliveries WHERE state = 'failed' GROUP BY host
            ) f
            WHERE NOT EXISTS (SELECT 1 FROM fediverse_blocked_hosts b WHERE b.host = f.host)
            GROUP BY f.host
            ORDER BY f.host
            LIMIT ?
            SQL, [self::UNREACHABLE]);

        return array_map(fn (object $row): array => ['host' => (string) $row->host, 'accounts' => (int) $row->accounts, 'failed' => (int) $row->failed], $rows);
    }

    /**
     * The accounts whose address isn't one of this site's /ap/ addresses,
     * which only the import brings, and their followers.
     *
     * @return array{accounts: int, followers: int}
     */
    private function imported(): array
    {
        $prefix = rtrim(url('/'), '/').'/'.ActivityPub::PREFIX.'/';
        $ids = FediverseActor::query()->whereRaw('left(uri, ?) <> ?', [mb_strlen($prefix), $prefix])->pluck('id');

        return [
            'accounts' => $ids->count(),
            'followers' => $ids->isEmpty() ? 0 : DB::table('fediverse_followers')->whereIn('local_actor_id', $ids)->count(),
        ];
    }
}

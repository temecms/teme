<?php

/**
 * The outside hosts a plugin's records name (the new host in a record's url
 * fields), kept in the plugin's consent: each record's latest hosts, and the
 * hosts a person agreed to. Core never reads a record's values again; it
 * keeps the hosts taken at save, and a host is reachable only once agreed.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins;

use App\Domain\Plugins\Models\PluginRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RecordHosts
{
    /**
     * The hosts each record named at its last save: its id, its title, the
     * host, and when it was saved.
     *
     * @param  array<string, mixed>  $consent
     *
     * @return list<array{record: string, title: string, host: string, at: string}>
     *
     * @since next
     */
    public static function named(array $consent): array
    {
        return array_values(array_filter(
            is_array($consent['recordHosts'] ?? null) ? $consent['recordHosts'] : [],
            fn (mixed $entry): bool => is_array($entry) && is_string($entry['host'] ?? null) && is_string($entry['record'] ?? null),
        ));
    }

    /**
     * The hosts a person agreed to for records, each once.
     *
     * @param  array<string, mixed>  $consent
     *
     * @return list<string>
     *
     * @since next
     */
    public static function agreed(array $consent): array
    {
        $hosts = [];

        foreach (is_array($consent['recordHostsAgreed'] ?? null) ? $consent['recordHostsAgreed'] : [] as $entry) {
            if (is_array($entry) && is_string($entry['host'] ?? null)) {
                $hosts[$entry['host']] = true;
            }
        }

        return array_keys($hosts);
    }

    /**
     * Records the hosts a record named at this save, replacing what it named
     * before, and returns those no person has agreed to yet. Runs inside the
     * save's transaction, so it writes nothing when the save is refused.
     *
     * @param  list<string>  $hosts  the hosts its url fields name now
     *
     * @return list<string> the hosts waiting for someone who manages plugins
     *
     * @since next
     */
    public static function noteSaved(string $name, string $recordId, string $title, array $hosts): array
    {
        $record = PluginRecord::query()->where('name', $name)->lockForUpdate()->first();

        if ($record === null) {
            return [];
        }

        $consent = $record->consent;
        $now = CarbonImmutable::now()->toIso8601String();
        $others = array_values(array_filter(self::named($consent), fn (array $entry): bool => $entry['record'] !== $recordId));
        $named = [...$others, ...array_map(fn (string $host): array => ['record' => $recordId, 'title' => $title, 'host' => $host, 'at' => $now], $hosts)];
        $agreed = self::agreed($consent);

        if ($named !== self::named($consent)) {
            $consent['recordHosts'] = $named;
            $record->consent = $consent;
            $record->save();
        }

        return array_values(array_unique(array_filter($hosts, fn (string $host): bool => ! in_array($host, $agreed, true))));
    }

    /**
     * Each host the records name that no person has agreed to, once.
     *
     * @param  array<string, mixed>  $consent
     *
     * @return list<string>
     *
     * @since next
     */
    public static function waiting(array $consent): array
    {
        $agreed = self::agreed($consent);
        $hosts = [];

        foreach (self::named($consent) as $entry) {
            if (! in_array($entry['host'], $agreed, true)) {
                $hosts[$entry['host']] = true;
            }
        }

        return array_keys($hosts);
    }

    /**
     * Records that a person agreed to these record hosts, now, by this person.
     *
     * @param  list<string>  $hosts
     *
     * @return list<string> the hosts agreed for records
     *
     * @since next
     */
    public static function agree(string $name, array $hosts, int $by): array
    {
        $record = PluginRecord::query()->where('name', $name)->lockForUpdate()->first();

        if ($record === null) {
            return [];
        }

        $consent = $record->consent;
        $agreed = self::agreed($consent);
        $new = array_values(array_diff(array_unique($hosts), $agreed));

        if ($new === []) {
            return $agreed;
        }

        $consent['recordHostsAgreed'] = [
            ...(is_array($consent['recordHostsAgreed'] ?? null) ? array_values($consent['recordHostsAgreed']) : []),
            ...array_map(fn (string $host): array => ['host' => $host, 'by' => $by, 'at' => CarbonImmutable::now()->toIso8601String()], $new),
        ];
        $record->consent = $consent;
        $record->save();
        DB::afterCommit(fn () => app(PluginRecords::class)->changed());

        return [...$agreed, ...$new];
    }

    /**
     * Removes a host agreed for records; the records that named it show the
     * waiting notice again, since their address is still kept.
     *
     * @return list<string> the hosts agreed for records afterwards
     *
     * @since next
     */
    public static function remove(string $name, string $host): array
    {
        $record = PluginRecord::query()->where('name', $name)->lockForUpdate()->first();

        if ($record === null) {
            return [];
        }

        $consent = $record->consent;
        $consent['recordHostsAgreed'] = array_values(array_filter(
            is_array($consent['recordHostsAgreed'] ?? null) ? $consent['recordHostsAgreed'] : [],
            fn (mixed $entry): bool => ! (is_array($entry) && ($entry['host'] ?? null) === $host),
        ));
        $record->consent = $consent;
        $record->save();
        DB::afterCommit(fn () => app(PluginRecords::class)->changed());

        return self::agreed($consent);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Placard;

use Closure;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Admin\AreaContext;
use Teme\Contracts\Admin\Chart;
use Teme\Contracts\Admin\Figure;
use Teme\Contracts\Admin\PluginArea;
use Teme\Contracts\Admin\Row;
use Teme\Contracts\Admin\RowPage;
use Teme\Contracts\Admin\Saved;
use Teme\Contracts\Admin\TableQuery;
use Teme\Contracts\Content\WriteRefused;
use Teme\Contracts\Lists\Cell;

/**
 * Placard's area: ads in its own table, placard_ads, read and written with
 * the query builder as the handbook allows. Each call can be replaced by a
 * test through $on, and every call is remembered in $calls.
 */
final class PlacardArea implements PluginArea
{
    /** @var array<string, Closure> what a test makes a call do instead, by method */
    public static array $on = [];

    /** @var list<array{0: string, 1: array<int, mixed>}> every call, its method and arguments */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$on = [];
        self::$calls = [];
    }

    public function figures(string $page, string $block, AreaContext $context): array
    {
        return $this->called(__FUNCTION__, func_get_args(), fn (): array => [
            'impressions' => Figure::number((int) DB::table('placard_ads')->sum('impressions'), 0.12),
            'ctr' => Figure::percent(0.031, -0.5),
        ]);
    }

    public function chart(string $page, string $block, AreaContext $context): Chart
    {
        return $this->called(__FUNCTION__, func_get_args(), fn (): Chart => new Chart(
            ['2026-11-01', '2026-11-02', '2026-11-03'],
            ['impressions' => [10, 20, 15], 'clicks' => [1, 3, 2], 'unknown' => [0, 0, 0]],
        ));
    }

    public function rows(string $record, TableQuery $query, AreaContext $context): RowPage
    {
        return $this->called(__FUNCTION__, func_get_args(), function () use ($query): RowPage {
            $builder = DB::table('placard_ads');

            if ($query->search !== '') {
                $builder->where('name', 'ilike', '%'.$query->search.'%');
            }

            if (isset($query->filters['status'])) {
                $builder->where('status', $query->filters['status']);
            }

            $total = $builder->count();
            $builder->orderBy($query->sort === 'impressions' ? 'impressions' : 'id', $query->direction);
            $rows = $builder->forPage($query->page, $query->perPage)->get()->map(function (object $ad): Row {
                /** @var array<string, mixed> $data */
                $data = json_decode((string) ($ad->data ?? '{}'), true) ?: [];
                $location = is_string($data['location'] ?? null) ? $data['location'] : 'between_items';
                $unavailable = [$ad->status === 'active' ? 'enable' : 'disable'];

                // Check only on active ads; Reset only when there are impressions.
                if ($ad->status !== 'active') {
                    $unavailable[] = 'check-code';
                }

                if ((int) $ad->impressions <= 0) {
                    $unavailable[] = 'reset-stats';
                }

                return new Row(
                    (string) $ad->id,
                    [
                        'name' => Cell::text((string) $ad->name),
                        'position' => Cell::text(match ($location) {
                            'after_paragraph' => 'After a paragraph',
                            'region' => 'In a theme region',
                            default => 'Between items on lists',
                        }),
                        'status' => Cell::badge($ad->status === 'active' ? 'Active' : 'Paused', $ad->status === 'active' ? 'ok' : 'neutral'),
                        'report' => Cell::link('Open report', 'https://ads.example/report/'.$ad->id),
                        'impressions' => Cell::number((int) $ad->impressions),
                    ],
                    $unavailable,
                );
            })->all();

            return new RowPage(array_values($rows), $total);
        });
    }

    public function values(string $record, string $id, AreaContext $context): ?array
    {
        return $this->called(__FUNCTION__, func_get_args(), function () use ($id): ?array {
            $ad = DB::table('placard_ads')->where('id', (int) $id)->first();

            if ($ad === null) {
                return null;
            }

            /** @var array<string, mixed> $data */
            $data = json_decode((string) $ad->data, true) ?: [];

            return ['name' => (string) $ad->name, ...$data];
        });
    }

    public function save(string $record, ?string $id, array $values, AreaContext $context): Saved
    {
        return $this->called(__FUNCTION__, func_get_args(), function () use ($id, $values): Saved {
            $name = (string) ($values['name'] ?? '');
            $data = $values;
            unset($data['name']);

            if ($id === null) {
                $new = DB::table('placard_ads')->insertGetId(['name' => $name, 'data' => json_encode($data), 'created_at' => now(), 'updated_at' => now()]);

                return new Saved((string) $new);
            }

            $stored = DB::table('placard_ads')->where('id', (int) $id)->value('data');
            /** @var array<string, mixed> $kept */
            $kept = json_decode((string) $stored, true) ?: [];

            DB::table('placard_ads')->where('id', (int) $id)->update(['name' => $name, 'data' => json_encode([...$kept, ...$data]), 'updated_at' => now()]);

            return new Saved($id);
        });
    }

    public function act(string $record, string $action, array $ids, AreaContext $context): Saved
    {
        return $this->called(__FUNCTION__, func_get_args(), function () use ($action, $ids): Saved {
            $rows = DB::table('placard_ads')->whereIn('id', array_map(intval(...), $ids));

            match ($action) {
                'enable' => $rows->update(['status' => 'active']),
                'disable' => $rows->update(['status' => 'paused']),
                'delete' => $rows->delete(),
                'duplicate' => $rows->get()->each(fn (object $ad) => DB::table('placard_ads')->insert(['name' => $ad->name.' (copy)', 'status' => $ad->status, 'data' => $ad->data, 'created_at' => now(), 'updated_at' => now()])),
                default => throw new WriteRefused('Placard doesn’t know that action.'),
            };

            return new Saved($ids[0] ?? 'none');
        });
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function called(string $method, array $arguments, Closure $otherwise): mixed
    {
        self::$calls[] = [$method, $arguments];

        return isset(self::$on[$method]) ? (self::$on[$method])(...$arguments) : $otherwise();
    }
}

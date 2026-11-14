<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Panels;

use App\Domain\Contents\Queries\ContentAbilities;
use App\Domain\Dashboard\DashboardPanelQuery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Scheduled: what will publish, soonest first. It reads
 * contents.scheduled_for, which 1.5e keeps equal to the pending schedule, so
 * a scheduled update to a live article counts as well as a new one. For the
 * types whose edit.any the viewer holds it shows every item
 * (contents_scheduled_for); for the rest, only their own
 * (contents_mine_scheduled_for). A held schedule says why; an item with a
 * time and no pending schedule is one whose schedule failed (B-167). One
 * query. It reads only.
 */
final class Scheduled implements DashboardPanelQuery
{
    /** Why a held schedule cannot run, in words. */
    private const array HELD = [
        'scheduler_cannot_publish' => 'the person who scheduled it can no longer publish',
        'scheduler_removed' => 'the person who scheduled it was removed',
    ];

    public function __construct(private readonly SettingsReader $settings) {}

    public function rows(User $viewer, array $types, int $limit): array
    {
        $viewerId = (int) $viewer->getAuthIdentifier();
        $everyone = [];
        $own = [];

        foreach ($types as $type) {
            if (ContentAbilities::forUser($viewer, $type->key)->editAny) {
                $everyone[] = $type->key;
            } else {
                $own[] = $type->key;
            }
        }

        $halves = [];
        $bindings = [];
        $columns = 'id, title, scheduled_for';

        // Each half is an index range scan with its own LIMIT; an empty half is
        // left out.
        if ($everyone !== []) {
            $halves[] = "(SELECT {$columns} FROM contents WHERE deleted_at IS NULL AND scheduled_for IS NOT NULL AND type IN (".self::marks($everyone).') ORDER BY scheduled_for, id LIMIT ?)';
            $bindings = [...$bindings, ...$everyone, $limit];
        }

        if ($own !== []) {
            $halves[] = "(SELECT {$columns} FROM contents WHERE deleted_at IS NULL AND scheduled_for IS NOT NULL AND author_id = ? AND type IN (".self::marks($own).') ORDER BY scheduled_for, id LIMIT ?)';
            $bindings = [...$bindings, $viewerId, ...$own, $limit];
        }

        $rows = DB::select(
            'SELECT due.id, due.title, due.scheduled_for, pending.id AS pending_id, pending.held_reason
             FROM ('.implode(' UNION ALL ', $halves).') due
             LEFT JOIN scheduled_publications pending
                ON pending.content_id = due.id
               AND pending.completed_at IS NULL AND pending.failed_at IS NULL AND pending.cancelled_at IS NULL
             ORDER BY due.scheduled_for, due.id
             LIMIT ?',
            [...$bindings, $limit],
        );

        $zone = $this->settings->string('general', 'timezone');

        return array_map(function (object $row) use ($zone): array {
            $at = CarbonImmutable::parse($row->scheduled_for);
            $when = $at->setTimezone($zone)->format('D j M H:i').' ('.$zone.')';

            return [
                'id' => (string) $row->id,
                'title' => (string) $row->title,
                'subtitle' => match (true) {
                    $row->pending_id === null => 'Failed · was due '.$when,
                    $row->held_reason !== null => 'Held: '.(self::HELD[(string) $row->held_reason] ?? (string) $row->held_reason).' · was due '.$when,
                    default => 'Publishes '.$when,
                },
                'url' => route('content.edit', ['content' => $row->id], false),
                'at' => $at->toIso8601String(),
            ];
        }, $rows);
    }

    /**
     * @param  list<string>  $values
     */
    private static function marks(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}

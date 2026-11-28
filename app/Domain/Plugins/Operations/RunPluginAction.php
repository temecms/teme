<?php

/**
 * Runs an action from a plugin's settings page or an area table row
 * (docs/proposals/plugin-sockets-1.md): queues the job it names as the
 * person who clicked, with the action in its payload, once while one waits
 * for that action and row. Returns the tracked job's ID and the action's
 * label.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Operations;

use App\Domain\Jobs\Operations\StartJob;
use App\Domain\Plugins\Area\AreaContexts;
use App\Domain\Plugins\Area\AreaPlugin;
use App\Domain\Plugins\Area\AreaReads;
use App\Domain\Plugins\Area\PluginAreas;
use App\Domain\Plugins\Jobs\PluginJobs;
use App\Domain\Plugins\Lists\PluginActions;
use App\Domain\Plugins\Operations\Input\RunPluginActionInput;
use App\Domain\Plugins\PluginAccess;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Admin\PluginArea;
use Teme\Contracts\Admin\TableQuery;
use Teme\Contracts\Jobs\QueueOptions;
use Teme\Contracts\Operations\OperationInput;

final class RunPluginAction extends BaseOperation
{
    /** The longest unique key a queued job may carry. */
    private const int KEY_LENGTH = 64;

    public function __construct(
        private readonly Actors $actors,
        private readonly PluginActions $actions,
        private readonly StartJob $start,
        private readonly PluginRegistry $registry,
        private readonly PluginAreas $areas,
        private readonly AreaReads $reads,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var RunPluginActionInput $input */
        $person = $this->actors->find($input->actorId);

        $allowed = $person !== null && ($person->can(PluginAccess::MANAGE) || ($this->areaPermission($input) && $person->can((string) $input->permission)));

        if (! $allowed || $this->actions->find($input->code, $input->action) === null) {
            throw new AuthorizationException(__('Not permitted.'));
        }
    }

    /** The longest record id a row action may name. */
    private const int MOST_RECORD = 120;

    protected function validate(OperationInput $input): void
    {
        /** @var RunPluginActionInput $input */
        $action = $this->actions->find($input->code, $input->action)
            ?? throw new AuthorizationException(__('Not permitted.'));

        $hasRecord = $input->recordId !== null && $input->recordId !== '';

        if ($action['row'] === true && ! $hasRecord) {
            throw ValidationException::withMessages(['record' => __('This action needs a row.')]);
        }

        if ($action['row'] !== true && $hasRecord) {
            throw ValidationException::withMessages(['record' => __('This action doesn’t run on a row.')]);
        }

        if ($hasRecord && mb_strlen((string) $input->recordId) > self::MOST_RECORD) {
            throw ValidationException::withMessages(['record' => __('That record id is too long.')]);
        }

        if ($hasRecord) {
            $this->refuseUnavailable($input);
        }
    }

    /**
     * Re-reads the row's unavailable list the same way the table does, and
     * refuses when this action is listed, or when the rows can't be read.
     * The job may still check; core refuses first so a stale menu or a
     * crafted post cannot queue work the UI hid.
     *
     * @since next
     */
    private function refuseUnavailable(RunPluginActionInput $input): void
    {
        $plugin = $this->areas->find($input->code);
        $table = $plugin === null ? null : $this->tableFor($input, $plugin);

        if ($plugin === null || $table === null || $input->recordId === null || $input->recordId === '') {
            return;
        }

        $unavailable = $this->unavailableFor($plugin, $table, $input->recordId, $input->actorId);

        if ($unavailable === false) {
            throw ValidationException::withMessages([
                'action' => __('That row couldn’t be checked just now. Try again.'),
            ]);
        }

        if ($unavailable !== null && in_array($input->action, $unavailable, true)) {
            throw ValidationException::withMessages([
                'action' => __('That action isn’t available for this row.'),
            ]);
        }
    }

    /**
     * The table this row action came from: one of the posted kind's tables
     * that offers the action, else the first table of any declared record
     * that does. A posted kind is a hint, never trusted on its own.
     *
     * @return array<string, mixed>|null
     *
     * @since next
     */
    private function tableFor(RunPluginActionInput $input, AreaPlugin $plugin): ?array
    {
        $posted = $input->recordKind !== null && $input->recordKind !== '' ? $input->recordKind : null;
        $found = null;

        foreach ($plugin->area->records as $record) {
            foreach ($plugin->area->tables($record['id']) as $table) {
                $actions = is_array($table['rowActions'] ?? null) ? $table['rowActions'] : [];

                if (! in_array($input->action, $actions, true)) {
                    continue;
                }

                if ($record['id'] === $posted) {
                    return $table;
                }

                $found ??= $table;
            }
        }

        return $found;
    }

    /**
     * Fresh unavailable action ids for this row, read under the area's
     * budgets; false when a read failed or ran over, and null when the row
     * isn't on the pages read (up to 1,000 rows). Later: a socket that reads
     * one row by id would close that last gap.
     *
     * @param  array<string, mixed>  $table
     *
     * @return list<string>|false|null
     *
     * @since next
     */
    private function unavailableFor(AreaPlugin $plugin, array $table, string $recordId, string $actorId): array|false|null
    {
        if (! $plugin->data instanceof PluginArea) {
            return false;
        }

        $context = AreaContexts::for($plugin->code, $actorId);

        for ($page = 1; $page <= 10; $page++) {
            $given = $this->reads->rows($plugin, $table, new TableQuery($page, 100, '', [], null, 'asc'), $context);

            if ($given['failed'] !== null) {
                return false;
            }

            foreach ($given['rows'] as $row) {
                if ($row['id'] === $recordId) {
                    return $row['unavailable'];
                }
            }

            if (count($given['rows']) < 100) {
                break;
            }
        }

        return null;
    }

    /**
     * Whether the permission passed is this plugin's area's own, for an
     * action its pages offer: from a plugin's own area, that permission
     * answers too (docs/plans/plugin-sockets-2.md, A6); no other does.
     */
    private function areaPermission(RunPluginActionInput $input): bool
    {
        $area = $input->permission === null ? null : $this->areas->find($input->code);

        return $area !== null && $input->permission === $area->ability() && in_array($input->action, $area->area->offeredActions(), true);
    }

    /**
     * @return array{job: string, label: string}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var RunPluginActionInput $input */
        $action = $this->actions->find($input->code, $input->action)
            ?? throw new AuthorizationException(__('Not permitted.'));

        $payload = ['action' => $action['id']];

        if ($input->recordId !== null && $input->recordId !== '') {
            $payload['record'] = $input->recordId;
        }

        $jobs = new PluginJobs($action['name'], $input->code, $this->start, $this->registry, JobActor::person($input->actorId), notifyOnFinish: true);
        $id = $jobs->queue(
            $action['job'],
            $payload,
            $action['label'],
            new QueueOptions(uniqueKey: self::uniqueKey($action['id'], $input->recordId)),
        );

        return ['job' => $id, 'label' => $action['label']];
    }

    /**
     * The key that keeps a second click from queuing the same work while
     * one waits: the action alone on a settings button, or the action and
     * the row when the click came from a table. The record id keeps its case;
     * a key over 64 characters is hashed.
     *
     * @since next
     */
    public static function uniqueKey(string $action, ?string $recordId = null): string
    {
        if ($recordId === null || $recordId === '') {
            return 'action:'.$action;
        }

        $key = "action:{$action}:{$recordId}";

        if (strlen($key) <= self::KEY_LENGTH) {
            return $key;
        }

        return 'action:'.substr(sha1($action.':'.$recordId), 0, self::KEY_LENGTH - strlen('action:'));
    }
}

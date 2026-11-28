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

        $jobs = new PluginJobs($action['name'], $input->code, $this->start, $this->registry, JobActor::person($input->actorId));
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

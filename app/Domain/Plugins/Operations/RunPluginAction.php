<?php

/**
 * Runs an action from a plugin's settings page (docs/proposals/plugin-sockets-1.md):
 * queues the job it names as the person who clicked, with the action in its
 * payload, once while one waits. Returns the tracked job's ID and the
 * action's label.
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
use Teme\Contracts\Jobs\QueueOptions;
use Teme\Contracts\Operations\OperationInput;

final class RunPluginAction extends BaseOperation
{
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

    protected function validate(OperationInput $input): void {}

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

        $jobs = new PluginJobs($action['name'], $input->code, $this->start, $this->registry, JobActor::person($input->actorId));
        $id = $jobs->queue($action['job'], ['action' => $action['id']], $action['label'], new QueueOptions(uniqueKey: "action:{$action['id']}"));

        return ['job' => $id, 'label' => $action['label']];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Operations;

use App\Domain\Jobs\Operations\StartJob;
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

/**
 * Runs an action from a plugin's settings page (docs/proposals/plugin-sockets-1.md):
 * queues the job it names as the person who clicked, with the action in its
 * payload, once while one waits. Returns the tracked job's ID and the
 * action's label.
 */
final class RunPluginAction extends BaseOperation
{
    public function __construct(
        private readonly Actors $actors,
        private readonly PluginActions $actions,
        private readonly StartJob $start,
        private readonly PluginRegistry $registry,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var RunPluginActionInput $input */
        $person = $this->actors->find($input->actorId);

        if ($person === null || ! $person->can(PluginAccess::MANAGE) || $this->actions->find($input->code, $input->action) === null) {
            throw new AuthorizationException(__('Not permitted.'));
        }
    }

    protected function validate(OperationInput $input): void {}

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

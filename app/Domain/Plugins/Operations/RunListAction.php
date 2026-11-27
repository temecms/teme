<?php

/**
 * Runs a list action (contributes.listActions) on a row or on the selected
 * items: queues the job it names once, as the person who asked, with the
 * items they can edit in its payload. Items they can't edit are left out and
 * counted; when none is left, nothing is queued. Returns the tracked job's ID,
 * the action's label and how many items were left out.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Operations;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Policies\ContentPolicy;
use App\Domain\Jobs\Operations\StartJob;
use App\Domain\Plugins\Jobs\PluginJobs;
use App\Domain\Plugins\Lists\ListActions;
use App\Domain\Plugins\Operations\Input\RunListActionInput;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Jobs\QueueOptions;
use Teme\Contracts\Operations\OperationInput;

final class RunListAction extends BaseOperation
{
    /** The most items one run may take. */
    public const int MOST_ITEMS = 100;

    /** The longest unique key a queued job may carry. */
    private const int KEY_LENGTH = 64;

    public function __construct(
        private readonly Actors $actors,
        private readonly ListActions $actions,
        private readonly StartJob $start,
        private readonly PluginRegistry $registry,
        private readonly ContentPolicy $policy,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var RunListActionInput $input */
        $person = $this->actors->find($input->actorId);

        if ($person === null || $this->actions->find($input->code, $input->action) === null) {
            throw new AuthorizationException(__('Not permitted.'));
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RunListActionInput $input */
        $action = $this->actions->find($input->code, $input->action)
            ?? throw new AuthorizationException(__('Not permitted.'));

        if ($input->itemIds === [] || count($input->itemIds) > self::MOST_ITEMS) {
            throw ValidationException::withMessages(['items' => __('Choose between 1 and :most items.', ['most' => self::MOST_ITEMS])]);
        }

        if ($input->bulk && ! $action['bulk']) {
            throw ValidationException::withMessages(['items' => __('This action isn’t offered in bulk.')]);
        }

        if (! $input->bulk && (! $action['row'] || count($input->itemIds) !== 1)) {
            throw ValidationException::withMessages(['items' => __('This action runs on one row at a time.')]);
        }
    }

    /**
     * @return array{job: string, label: string, skipped: int}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var RunListActionInput $input */
        $person = $this->actors->findOrFail($input->actorId);
        $action = $this->actions->find($input->code, $input->action)
            ?? throw new AuthorizationException(__('Not permitted.'));

        $contents = Content::query()->whereIn('id', $input->itemIds)->get()->keyBy(fn (Content $content): string => (string) $content->id);
        $editable = [];

        foreach (array_values(array_unique($input->itemIds)) as $itemId) {
            $content = $contents->get($itemId);

            if ($content !== null && $this->policy->update($person, $content)) {
                $editable[] = (string) $content->id;
            }
        }

        if ($editable === []) {
            throw new AuthorizationException(__('Not permitted.'));
        }

        $jobs = new PluginJobs($action['name'], $input->code, $this->start, $this->registry, JobActor::person($input->actorId));
        $id = $jobs->queue(
            $action['job'],
            ['action' => $action['id'], 'items' => $editable],
            $action['label'],
            new QueueOptions(uniqueKey: self::uniqueKey($action['id'], $editable)),
        );

        return ['job' => $id, 'label' => $action['label'], 'skipped' => count($input->itemIds) - count($editable)];
    }

    /**
     * The key that keeps a second click from queuing the same work while one
     * waits: the action and the items, shortened to a hash.
     *
     * @param  list<string>  $items
     */
    public static function uniqueKey(string $action, array $items): string
    {
        sort($items);

        return substr('list:'.sha1("{$action}:".implode(',', $items)), 0, self::KEY_LENGTH);
    }
}

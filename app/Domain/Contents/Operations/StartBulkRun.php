<?php

/**
 * Starts Select all matching (I-143): a run over one content list, as its filters stand, with its own tracked job, its first step queued once both have committed. Each item is authorized as the run reaches it; this checks only that the person may work on the type's list at all.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations;

use App\Domain\Contents\BulkRunJobKind;
use App\Domain\Contents\BulkRunWords;
use App\Domain\Contents\Jobs\AdvanceBulkRun;
use App\Domain\Contents\Models\BulkRun;
use App\Domain\Contents\Operations\Input\StartBulkRunInput;
use App\Domain\Contents\Queries\ContentList;
use App\Domain\Contents\Queries\ContentListFilters;
use App\Domain\Jobs\Operations\Input\StartJobInput;
use App\Domain\Jobs\Operations\StartJob;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Settings\SettingsReader;

final class StartBulkRun extends BaseOperation
{
    public function __construct(
        private Actors $actors,
        private ContentTypeRegistry $types,
        private ContentList $list,
        private StartJob $startJob,
        private BulkRunWords $words,
        private SettingsReader $settings,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var StartBulkRunInput $input */
        $person = $this->actors->find($input->actorId());
        $type = $this->type($input);

        // A null actor, the operator, is refused too: runs are a person's.
        if ($person === null || $type === null || ! $person->can($type->ability('create')) || ! ($person->can($type->ability('delete.own')) || $person->can($type->ability('delete.any')))) {
            throw new AuthorizationException('Only someone who may change this list’s items starts a run over it.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var StartBulkRunInput $input */
        Validator::make(['action' => $input->action], ['action' => ['required', 'string', Rule::in(BulkRunWords::ACTIONS)]])->validate();

        $type = $this->types->get($input->type);
        $tab = ContentListFilters::read($input->filters, $type, $this->settings->string('general', 'timezone'))->tab();

        if ($input->action === 'trash' && $tab === 'trash') {
            throw ValidationException::withMessages(['action' => __('These are in the trash already.')]);
        }

        if ($input->action !== 'trash' && $tab !== 'trash') {
            throw ValidationException::withMessages(['action' => __('Only items in the trash can be restored or deleted permanently.')]);
        }
    }

    /**
     * @return array{runId: string, jobId: string, label: string}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var StartBulkRunInput $input */
        $type = $this->types->get($input->type);
        $actorId = (string) $input->actorId();
        $filters = ContentListFilters::read($input->filters, $type, $this->settings->string('general', 'timezone'));

        // The tab's label and exact count, and whether a search or a filter
        // narrows the list, as the bar read them from the same page (I-140).
        $page = $this->list->page($filters->request($type, (int) $actorId, 25));
        $tab = collect($page['tabs'])->firstWhere('key', $filters->tab()) ?? $page['tabs'][0];
        $narrowed = $page['filtered'];
        $label = $this->words->label($type, $input->action, (string) $tab['key'], (string) $tab['label'], $narrowed);

        $runId = strtolower((string) Str::ulid());
        $jobId = $this->startJob->execute(new StartJobInput(
            kind: BulkRunJobKind::KEY,
            label: $label,
            total: $narrowed ? null : (int) $tab['count'],
            runType: BulkRunJobKind::RUN_TYPE,
            runId: $runId,
            actorId: $actorId,
        ));

        BulkRun::query()->create([
            'id' => $runId,
            'tracked_job_id' => $jobId,
            'user_id' => (int) $actorId,
            'content_type' => $type->key,
            'action' => $input->action,
            'filters' => $filters->values,
        ]);

        // After commit, so the first step never looks for a run that isn't there yet.
        DB::afterCommit(fn () => AdvanceBulkRun::dispatch($runId, JobActor::person($actorId)));

        return ['runId' => $runId, 'jobId' => $jobId, 'label' => $label];
    }

    /**
     * The type the input names, or null when none is registered or listed.
     */
    private function type(StartBulkRunInput $input): ?ContentType
    {
        try {
            $type = $this->types->get($input->type);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $type->listed ? $type : null;
    }
}

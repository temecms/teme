<?php

/**
 * Starts bringing back the page parents of a site imported before (I-3363):
 * a tracked job that puts its pages back under their WordPress parents, at
 * their WordPress addresses, acting for whoever asked. Refused while a run
 * of the site is running, or when none of its pages had a parent. It reads
 * nothing from the old site, so it needs no password.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\Jobs\BringBackPageParents;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\StartPageParentsJobInput;
use App\Domain\Import\Queries\ImportedPageTree;
use App\Domain\Import\Queries\ImportRuns;
use App\Domain\Import\Queries\ImportSources;
use App\Domain\Import\Tracking\PageParentsJobKind;
use App\Domain\Jobs\Operations\Input\StartJobInput;
use App\Domain\Jobs\Operations\StartJob;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\JobActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Teme\Contracts\Operations\OperationInput;

final class StartPageParentsJob extends BaseOperation
{
    use AuthorizesImports;

    public function __construct(
        private readonly ImportSources $sources,
        private readonly ImportRuns $runs,
        private readonly ImportedPageTree $tree,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var StartPageParentsJobInput $input */
        if (! Str::isUlid($input->sourceId) || $this->sources->find($input->sourceId) === null) {
            throw new InvalidArgumentException('Bringing back page parents needs a site Teme keeps.');
        }
    }

    /**
     * Answers the tracked job's ID.
     */
    protected function perform(OperationInput $input): string
    {
        /** @var StartPageParentsJobInput $input */
        $site = (string) $this->sources->find($input->sourceId)?->address;

        if ($this->runs->running($input->sourceId) !== null) {
            throw ValidationException::withMessages(['source' => __('An import of :site is running. Bring back page parents once it ends.', ['site' => $site])]);
        }

        if ($this->tree->withParents($input->sourceId) === 0) {
            throw ValidationException::withMessages(['source' => __("No page imported from :site sat under another page, so there's nothing to bring back.", ['site' => $site])]);
        }

        $jobId = app(StartJob::class)->execute(new StartJobInput(
            kind: PageParentsJobKind::KEY,
            label: __('Bringing back page parents from :site', ['site' => $site]),
            total: $this->tree->pages($input->sourceId),
            runType: PageParentsJobKind::RUN_TYPE,
            runId: $input->sourceId,
            actorId: $input->actorId(),
        ));
        $sourceId = $input->sourceId;
        $actor = JobActor::of($input->actorId());

        // After commit, so the first step never runs before the job is there.
        DB::afterCommit(fn () => BringBackPageParents::dispatch($jobId, $sourceId, null, $actor));

        return $jobId;
    }
}

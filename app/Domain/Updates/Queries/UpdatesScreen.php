<?php

/**
 * What Tools › Updates shows (I-3329): the version running, the releases after it with their notes,
 * the update check, the last deploys, and the update request in hand with its steps. It reads only.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Updates\Queries;

use App\Domain\Deployment\Health\Heartbeats;
use App\Domain\Deployment\Queries\DeployRuns;
use App\Domain\Updates\Release;
use App\Domain\Updates\ReleaseNotes;
use App\Domain\Updates\Requests\TemeUpdate;
use App\Domain\Updates\Requests\UpdateKind;
use App\Domain\Updates\Requests\UpdateStatus;
use App\Domain\Updates\TemeVersion;
use App\Domain\Updates\UpdateChannel;
use App\Domain\Updates\UpdatePlan;
use Illuminate\Support\Facades\DB;

final readonly class UpdatesScreen
{
    public function __construct(
        private UpdateState $state,
        private DeployRuns $deploys,
        private TemeUpdates $updates,
        private UpdatePlan $plan,
        private Heartbeats $heartbeats,
    ) {}

    /**
     * The screen's props.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $check = $this->state->check();
        $revision = config('teme.revision');
        $newest = $this->state->newest();
        $previous = $this->plan->previous();

        return [
            'running' => [
                'version' => TemeVersion::current(),
                'tagged' => TemeVersion::tagged(),
                'revision' => is_string($revision) ? substr($revision, 0, 12) : null,
                'channel' => UpdateChannel::current(),
                'channelSet' => UpdateChannel::isSet(),
            ],
            'newer' => array_map($this->release(...), $this->state->newer()),
            'newest' => $newest === null ? null : $this->release($newest),
            'check' => [
                'enabled' => (bool) config('teme.updates.check'),
                'checkedAt' => $check === null ? null : $check['checkedAt']->toIso8601String(),
                'succeededAt' => $check === null ? null : $check['succeededAt']?->toIso8601String(),
                'error' => $check['error'] ?? null,
                'tokenSet' => $check['tokenSet'] ?? false,
            ],
            'deploys' => array_map(fn (array $run): array => [
                'version' => $run['version'],
                'outcome' => $run['outcome'],
                'startedAt' => $run['startedAt']->toIso8601String(),
                'finishedAt' => $run['finishedAt']->toIso8601String(),
                'migrations' => $run['migrations'],
                'backupRun' => $run['backupRun'],
                'sentence' => $run['sentence'],
            ], $this->deploys->recent()),
            'update' => ($shown = $this->updates->latestShown()) === null ? null : $this->update($shown),
            'rollback' => $previous === null ? null : ['version' => $previous, 'refusal' => $this->plan->refusal(UpdateKind::Rollback, $previous)],
            'updaterSeenAt' => $this->heartbeats->lastBeat(Heartbeats::UPDATER)?->toIso8601String(),
            'canUpdate' => $newest === null ? null : $this->plan->refusal(UpdateKind::Update, $newest->version),
        ];
    }

    /**
     * The request the screen shows. Each step keeps the five keys the page draws; the platform's deployment IDs and the other bookkeeping stay with the updater.
     *
     * @return array{id: string, kind: string, from: string, to: string, status: string, step: ?string, steps: list<array{step: string, state: string, startedAt: ?string, finishedAt: ?string, detail: ?string}>, error: ?string, backupRun: ?string, requestedBy: ?string, requestedAt: string, startedAt: ?string, finishedAt: ?string, canCancel: bool}
     */
    private function update(TemeUpdate $update): array
    {
        return [
            'id' => $update->id,
            'kind' => $update->kind->value,
            'from' => $update->from,
            'to' => $update->to,
            'status' => $update->status->value,
            'step' => $update->step?->value,
            'steps' => array_map(fn (array $entry): array => [
                'step' => $entry['step'],
                'state' => $entry['state'],
                'startedAt' => $entry['startedAt'],
                'finishedAt' => $entry['finishedAt'],
                'detail' => $entry['detail'],
            ], $update->steps),
            'error' => $update->error,
            'backupRun' => $update->backupRunId,
            'requestedBy' => $this->requester($update->requestedBy),
            'requestedAt' => $update->requestedAt->toIso8601String(),
            'startedAt' => $update->startedAt?->toIso8601String(),
            'finishedAt' => $update->finishedAt?->toIso8601String(),
            'canCancel' => $update->status === UpdateStatus::Requested,
        ];
    }

    /**
     * @return array{version: string, prerelease: bool, security: bool, maintenance: bool, publishedAt: string, url: ?string, notes: list<array{heading: ?string, paragraphs: list<string>, items: list<string>}>}
     */
    private function release(Release $release): array
    {
        return [
            'version' => $release->version,
            'prerelease' => $release->prerelease,
            'security' => $release->security,
            'maintenance' => $release->maintenance,
            'publishedAt' => $release->publishedAt->toIso8601String(),
            'url' => $release->url,
            'notes' => ReleaseNotes::outline($release->notes),
        ];
    }

    /** Returns the name of the person who asked, or null for the operator. */
    private function requester(?int $id): ?string
    {
        $name = $id === null ? null : DB::table('users')->where('id', $id)->value('name');

        return $name === null ? null : (string) $name;
    }
}

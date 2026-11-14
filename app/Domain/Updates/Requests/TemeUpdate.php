<?php

declare(strict_types=1);

namespace App\Domain\Updates\Requests;

use Carbon\CarbonImmutable;

/**
 * One update request as the teme_updates row holds it (I-3326): both versions, where it stands, the step in hand and every step's record.
 */
final readonly class TemeUpdate
{
    /**
     * @param  list<array{step: string, state: string, startedAt: ?string, finishedAt: ?string, detail: ?string, asks: int, runsAfter: ?string, mark: ?string}>  $steps
     */
    public function __construct(
        public string $id,
        public UpdateKind $kind,
        public string $from,
        public string $to,
        public UpdateStatus $status,
        public ?UpdateStep $step,
        public array $steps,
        public ?string $error,
        public ?string $backupRunId,
        public ?string $deployRunId,
        public ?int $requestedBy,
        public ?int $cancelledBy,
        public CarbonImmutable $requestedAt,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $finishedAt,
    ) {}

    /**
     * Returns the record of one step, or null when the request has none (it hasn't begun).
     *
     * @return array{step: string, state: string, startedAt: ?string, finishedAt: ?string, detail: ?string, asks: int, runsAfter: ?string, mark: ?string}|null
     */
    public function entry(UpdateStep $step): ?array
    {
        foreach ($this->steps as $entry) {
            if ($entry['step'] === $step->value) {
                return $entry;
            }
        }

        return null;
    }

    /** Returns the state a step is recorded in; pending before the request has begun. */
    public function state(UpdateStep $step): string
    {
        return $this->entry($step)['state'] ?? UpdateStep::PENDING;
    }

    /** Returns the first step that isn't done, or null when every step is. */
    public function currentStep(): ?UpdateStep
    {
        foreach (UpdateStep::cases() as $step) {
            if ($this->state($step) !== UpdateStep::DONE) {
                return $step;
            }
        }

        return null;
    }

    /** Whether the last check failed or is switched back, which is how a switch back is recorded as under way. */
    public function switchingBack(): bool
    {
        return in_array($this->state(UpdateStep::Check), [UpdateStep::FAILED, UpdateStep::SWITCHED_BACK], true);
    }

    /**
     * A request read back from a teme_updates row.
     */
    public static function fromRow(object $row): self
    {
        $steps = json_decode((string) $row->steps, true);

        return new self(
            id: (string) $row->id,
            kind: UpdateKind::from((string) $row->kind),
            from: (string) $row->from_version,
            to: (string) $row->to_version,
            status: UpdateStatus::from((string) $row->status),
            step: $row->step === null ? null : UpdateStep::from((string) $row->step),
            steps: is_array($steps) ? array_values($steps) : [],
            error: $row->error === null ? null : (string) $row->error,
            backupRunId: $row->backup_run_id === null ? null : (string) $row->backup_run_id,
            deployRunId: $row->deploy_run_id === null ? null : (string) $row->deploy_run_id,
            requestedBy: $row->requested_by === null ? null : (int) $row->requested_by,
            cancelledBy: $row->cancelled_by === null ? null : (int) $row->cancelled_by,
            requestedAt: CarbonImmutable::parse((string) $row->requested_at),
            startedAt: $row->started_at === null ? null : CarbonImmutable::parse((string) $row->started_at),
            finishedAt: $row->finished_at === null ? null : CarbonImmutable::parse((string) $row->finished_at),
        );
    }
}

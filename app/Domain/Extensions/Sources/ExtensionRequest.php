<?php

declare(strict_types=1);

namespace App\Domain\Extensions\Sources;

use App\Domain\Extensions\ExtensionAction;
use App\Domain\Extensions\ExtensionKind;
use App\Domain\Extensions\RequestStatus;
use App\Domain\Extensions\RequestStep;
use Carbon\CarbonImmutable;

/**
 * One extension request as the extension_requests row holds it: what it
 * asks for, where it comes from, where it stands and what it found. A
 * value, never a model, so the source contract carries no Eloquent type.
 */
final readonly class ExtensionRequest
{
    /**
     * @param  list<array{step: string, state: string, startedAt: ?string, finishedAt: ?string, detail: ?string}>  $steps
     * @param  array<string, mixed>  $needs
     * @param  array<string, mixed>  $agreed
     * @param  array<string, mixed>  $report
     */
    public function __construct(
        public string $id,
        public ExtensionAction $action,
        public ExtensionKind $kind,
        public ?string $package,
        public ?string $constraint,
        public ?string $sourceKind,
        public ?string $sourceId,
        public ?string $uploadId,
        public RequestStatus $status,
        public ?RequestStep $step,
        public array $steps,
        public array $needs,
        public array $agreed,
        public array $report,
        public ?string $error,
        public ?string $fromRelease,
        public ?string $toRelease,
        public ?int $requestedBy,
        public ?int $agreedBy,
        public ?int $cancelledBy,
        public CarbonImmutable $requestedAt,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $finishedAt,
    ) {}

    /**
     * A request read back from an extension_requests row, as the query
     * builder or a model's attributes give it.
     */
    public static function fromRow(object $row): self
    {
        return new self(
            id: (string) $row->id,
            action: ExtensionAction::from((string) $row->action),
            kind: ExtensionKind::from((string) $row->kind),
            package: self::text($row->package),
            constraint: self::text($row->constraint),
            sourceKind: self::text($row->source_kind),
            sourceId: self::text($row->source_id),
            uploadId: self::text($row->upload_id),
            status: RequestStatus::from((string) $row->status),
            step: $row->step === null ? null : RequestStep::from((string) $row->step),
            steps: array_values(self::json($row->steps)),
            needs: self::json($row->needs),
            agreed: self::json($row->agreed),
            report: self::json($row->report),
            error: self::text($row->error),
            fromRelease: self::text($row->from_release),
            toRelease: self::text($row->to_release),
            requestedBy: $row->requested_by === null ? null : (int) $row->requested_by,
            agreedBy: $row->agreed_by === null ? null : (int) $row->agreed_by,
            cancelledBy: $row->cancelled_by === null ? null : (int) $row->cancelled_by,
            requestedAt: CarbonImmutable::parse((string) $row->requested_at),
            startedAt: $row->started_at === null ? null : CarbonImmutable::parse((string) $row->started_at),
            finishedAt: $row->finished_at === null ? null : CarbonImmutable::parse((string) $row->finished_at),
        );
    }

    /** Whether the request is still open. */
    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    private static function text(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * @return array<mixed>
     */
    private static function json(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? $decoded : [];
    }
}

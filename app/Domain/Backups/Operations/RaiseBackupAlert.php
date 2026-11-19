<?php

/**
 * Decides whether the administrators are emailed about the backups now
 * (I-2409), and records it: each failure at once, on its own, then again
 * at most once a day while it lasts, and once when the verdict is no longer
 * red. The email is queued after commit. Each alert also goes to every
 * administrator's inbox, in the same transaction. Returns the new alert's ID, or
 * null when no email is due.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Operations;

use App\Domain\Backups\BackupAlertKind;
use App\Domain\Backups\BackupSettings;
use App\Domain\Backups\Jobs\SendBackupAlertEmails;
use App\Domain\Backups\Models\BackupAlert;
use App\Domain\Backups\Operations\Concerns\AuthorizesBackupRuns;
use App\Domain\Backups\Queries\BackupAdministrators;
use App\Domain\Backups\Queries\BackupStatus;
use App\Domain\Inbox\InboxLink;
use App\Domain\Inbox\Operations\Input\SendToInboxInput;
use App\Domain\Inbox\Operations\SendToInbox;
use App\Domain\Support\Operations\BaseOperation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Settings\SettingsReader;

final class RaiseBackupAlert extends BaseOperation
{
    use AuthorizesBackupRuns;

    /** Hours before a reminder while the problem lasts. */
    public const int REMIND_AFTER_HOURS = 24;

    /** The advisory lock that makes one decision at a time. */
    public const string LOCK = 'teme.backup.alert';

    public function __construct(
        private readonly BackupStatus $status,
        private readonly BackupAdministrators $administrators,
        private readonly SendToInbox $inbox,
        private readonly SettingsReader $settings,
    ) {}

    protected function validate(OperationInput $input): void {}

    protected function perform(OperationInput $input): ?string
    {
        // One decision at a time, from every server's scheduler, until this transaction ends.
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [self::LOCK]);

        $now = CarbonImmutable::now();
        $verdict = $this->status->verdict($now);

        // The current run of alerts, newest first: every one since the newest fix (backup_alerts_raised_index).
        $lastFixed = BackupAlert::query()->where('kind', BackupAlertKind::Fixed->value)->orderByDesc('raised_at')->value('raised_at');
        $current = BackupAlert::query()->where('kind', '<>', BackupAlertKind::Fixed->value)->orderByDesc('raised_at');

        if ($lastFixed !== null) {
            $current->where('raised_at', '>', $lastFixed);
        }

        $run = $current->get();

        // When each failure, one of the verdict's sentences, was last told in this run.
        $told = [];

        foreach ($run as $earlier) {
            foreach ($earlier->problems as $problem) {
                $told[$problem] ??= $earlier->raised_at;
            }
        }

        $remindFrom = $now->subHours(self::REMIND_AFTER_HOURS);
        $new = array_values(array_filter($verdict->alerting, fn (string $problem): bool => ! isset($told[$problem])));
        $due = array_values(array_filter($verdict->alerting, fn (string $problem): bool => isset($told[$problem]) && $told[$problem]->lessThanOrEqualTo($remindFrom)));

        // A new failure at once, on its own; then reminders a day after each was last told; then the fix.
        [$kind, $problems] = match (true) {
            $new !== [] => [BackupAlertKind::Failing, $new],
            $due !== [] => [BackupAlertKind::Reminder, $due],
            $verdict->alerting === [] && $run->isNotEmpty() && ! $verdict->isRed() => [BackupAlertKind::Fixed, []],
            default => [null, []],
        };

        if ($kind === null) {
            return null;
        }

        $alert = BackupAlert::query()->create([
            'kind' => $kind,
            'problems' => $problems,
            'raised_at' => $now,
        ]);

        // Each administrator's inbox, in this transaction: an alert recorded is an alert told (I-221).
        $this->tell($kind, $problems, $verdict->lastGoodBackup, $input->actorId());

        // The email goes only once this decision is committed.
        DB::afterCommit(fn () => SendBackupAlertEmails::dispatch($alert->id));

        return $alert->id;
    }

    /**
     * One item in each administrator's inbox for this alert, in the email's
     * order of news (I-2409), linking to Tools › Backups.
     *
     * @param  list<string>  $problems
     */
    private function tell(BackupAlertKind $kind, array $problems, ?CarbonImmutable $lastGood, ?string $actorId): void
    {
        $when = $lastGood?->setTimezone($this->settings->string('general', 'timezone'))->format('j F Y \a\t H:i');

        [$title, $text] = match ($kind) {
            BackupAlertKind::Failing => [__('Backups are failing'), implode(' ', $problems)],
            BackupAlertKind::Reminder => [__('Backups are still failing'), implode(' ', $problems)],
            BackupAlertKind::Fixed => [__('Backups are working again'), $when === null ? null : __('The newest backup is from :when, and its checks passed.', ['when' => $when])],
        };

        foreach ($this->administrators->all() as $person) {
            $this->inbox->execute(new SendToInboxInput(
                recipientId: (string) $person->id,
                kind: 'backup.'.$kind->value,
                title: $title,
                text: $text === null ? null : mb_strimwidth($text, 0, 1000, '…'),
                link: new InboxLink(BackupSettings::SCREEN, [], 'backups.manage'),
                actorId: $actorId,
            ));
        }
    }
}

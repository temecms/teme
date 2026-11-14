<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Operations;

use App\Domain\Privacy\ExportFile;
use App\Domain\Privacy\Models\PrivacyRequest;
use App\Domain\Privacy\Notifications\PersonalDataExported;
use App\Domain\Privacy\Operations\Concerns\StepsThroughHolders;
use App\Domain\Privacy\PrivacyStep;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Teme\Contracts\Privacy\PersonalDataHolder;
use Teme\Contracts\Privacy\PersonalDataSubject;

/**
 * Takes one step through a request for a person's personal data (I-3375):
 * the next holder's next page, written to its own file; once every holder
 * is done, the one file the person gets, and the pages deleted after commit,
 * with the file emailed to the address when the request asks.
 */
final class RunPrivacyExport extends BaseOperation
{
    use StepsThroughHolders;

    /**
     * The most the page files of one export may hold, together: five times
     * the largest file emailed, and more than a person's own data comes to
     * (a comment exports in about 1 KB), yet small enough that the file can
     * be assembled in memory and a holder that never ends can't fill the disk.
     */
    public const int MOST_BYTES = 100 * 1024 * 1024;

    protected function kind(): string
    {
        return PrivacyRequest::EXPORT;
    }

    protected function askPage(PrivacyRequest $request, PersonalDataHolder $holder, PersonalDataSubject $subject, array $entry): array
    {
        $page = (int) $entry['page'] + 1;
        $answer = $holder->export($subject, $page);
        $contents = app(ExportFile::class)->pageContents($answer);
        $bytes = strlen($contents);

        // Checked before the page is written, so a refused page leaves no file.
        if (array_sum(array_map(fn (array $held): int => (int) ($held['bytes'] ?? 0), $request->results)) + $bytes > self::MOST_BYTES) {
            throw new RuntimeException(__('The holder :label took the export past :size MB.', ['label' => $entry['label'], 'size' => self::MOST_BYTES / 1024 / 1024]));
        }

        app(ExportFile::class)->writePage($request->id, (string) $entry['key'], $page, $contents);

        $entry['page'] = $page;
        $entry['bytes'] = (int) ($entry['bytes'] ?? 0) + $bytes;
        $entry['found'] = (int) $entry['found'] + count($answer->groups);
        $entry['done'] = $answer->done;

        return [$entry, $answer->groups !== []];
    }

    protected function finish(PrivacyRequest $request): void
    {
        $request->export_path = app(ExportFile::class)->assemble($request);
    }

    protected function afterCommit(mixed $result): void
    {
        /** @var PrivacyStep $result */
        if (! $result->done) {
            return;
        }

        $request = PrivacyRequest::query()->find($result->requestId);

        if ($request !== null) {
            // Kept until now, so a rolled-back finish could be built again from them.
            app(ExportFile::class)->delete(app(ExportFile::class)->pagePaths($request->id, $request->results));

            // To the address the request names, often a commenter with no account; a file too large to send is the operator's to send.
            if ($request->reply && $request->email !== null && $request->export_path !== null && app(ExportFile::class)->fitsInEmail($request->export_path)) {
                Notification::route('mail', $request->email)->notify(new PersonalDataExported($request->export_path));
            }
        }
    }
}

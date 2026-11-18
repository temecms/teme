<?php

/**
 * Imports redirects from a CSV file of from, to, status, note and on, for
 * whoever holds redirects.manage (I-850, I-872): each line checked as one
 * added by hand, against the table and the lines before it; a line that
 * fails is left out and reported with its number and why; the rest added,
 * marked imported. One entry in the security log for the file; the stored
 * "not found" pages they replace purged after commit.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Redirects\Operations;

use App\Domain\Redirects\Models\Redirect;
use App\Domain\Redirects\Operations\Input\ImportRedirectsInput;
use App\Domain\Redirects\RedirectChanges;
use App\Domain\Redirects\RedirectLookup;
use App\Domain\Redirects\RedirectProposal;
use App\Domain\Redirects\RedirectRefused;
use App\Domain\Redirects\RedirectRules;
use App\Domain\Security\SecurityEventType;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class ImportRedirects extends BaseOperation
{
    /** The most lines a file may hold. */
    public const int MOST = 5000;

    public function __construct(
        private readonly RedirectRules $rules,
        private readonly RedirectChanges $changes,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var ImportRedirectsInput $input */
        $actor = app(Actors::class)->find($input->actorId());

        if ($actor === null || ! $actor->can('redirects.manage')) {
            throw new AuthorizationException('Not permitted to manage redirects.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ImportRedirectsInput $input */
        self::rows($input->csv);
    }

    /**
     * @return array{added: int, refused: list<array{line: int, reason: string}>}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ImportRedirectsInput $input */
        RedirectRules::lock();
        $actor = $input->actorId() === null ? null : (int) $input->actorId();
        $added = [];
        $refused = [];

        foreach (self::rows($input->csv) as [$line, $cells]) {
            [$from, $to, $status, $note, $on] = array_pad($cells, 5, '');

            try {
                if ($status !== '' && preg_match('/^\d{3}$/', $status) !== 1) {
                    throw new RedirectRefused('status', __(':status isn’t a status a redirect can have.', ['status' => $status]));
                }

                if (! in_array(mb_strtolower($on), ['', 'yes', 'no'], true)) {
                    throw new RedirectRefused('on', __('Write yes or no in the on column, or leave it empty.'));
                }

                $proposal = RedirectProposal::read($from, $status === '' ? 301 : (int) $status, $to, null, null, $note);
                $this->rules->check($proposal, null);
            } catch (RedirectRefused $failed) {
                // A status RedirectProposal refuses says so in the import's own words.
                $reason = $failed->field === 'status' ? __(':status isn’t a status a redirect can have.', ['status' => $status]) : $failed->getMessage();
                $refused[] = ['line' => $line, 'reason' => __('Line :line: :reason', ['line' => $line, 'reason' => $reason])];

                continue;
            }

            $redirect = Redirect::query()->create([
                ...$proposal->columns(),
                'origin' => 'import',
                'enabled' => mb_strtolower($on) !== 'no',
                'created_by' => $actor,
                'updated_by' => $actor,
            ]);
            $added[] = $redirect->only(['source', 'kind']);
        }

        $this->changes->record(SecurityEventType::RedirectsImported, $input->actorId(), ['added' => count($added), 'refused' => count($refused)]);

        if ($added !== []) {
            $this->purgeAfterCommit(RedirectChanges::purged($added));
        }

        return ['added' => count($added), 'refused' => $refused];
    }

    protected function invalidateCache(mixed $result): void
    {
        Cache::memo()->forget(RedirectLookup::PATTERNS_KEY);
    }

    /**
     * The file's rows, each with its number, a header row and blank rows
     * left out, every cell trimmed and the `'` an export puts before a
     * formula's first character taken off again; or the refusal of a file
     * that isn't UTF-8, holds more than MOST rows, or none.
     *
     * @return list<array{0: int, 1: list<string>}>
     */
    private static function rows(string $csv): array
    {
        $csv = str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;

        if (! mb_check_encoding($csv, 'UTF-8')) {
            throw ValidationException::withMessages(['csv' => [__('The file isn’t in UTF-8. Save it as CSV UTF-8 and import it again.')]]);
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];
        $line = 0;

        while (($cells = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $line++;

            if ($cells === [null]) {
                continue;
            }

            $cells = array_map(fn (?string $cell): string => (string) preg_replace("/^'(?=[=+\\-@\t\r])/", '', trim((string) $cell)), $cells);

            if ($line === 1 && mb_strtolower($cells[0]) === 'from') {
                continue;
            }

            if (count($rows) === self::MOST) {
                fclose($stream);

                throw ValidationException::withMessages(['csv' => [__('This file has more than 5,000 lines. Split it, and import each part.')]]);
            }

            $rows[] = [$line, $cells];
        }

        fclose($stream);

        if ($rows === []) {
            throw ValidationException::withMessages(['csv' => [__('Choose a CSV file with at least one redirect in it.')]]);
        }

        return $rows;
    }
}

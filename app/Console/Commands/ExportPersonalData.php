<?php

/**
 * teme:privacy:export — writes every piece of personal data the site holds
 * for an address to one JSON file and emails it there, as the operator,
 * who has checked who asked (I-3375). A run a killed process left is
 * continued.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Privacy\ExportFile;
use App\Domain\Privacy\Operations\FailPrivacyRequest;
use App\Domain\Privacy\Operations\Input\FailPrivacyRequestInput;
use App\Domain\Privacy\Operations\Input\RecordPrivacyRequestInput;
use App\Domain\Privacy\Operations\Input\RunPrivacyExportInput;
use App\Domain\Privacy\Operations\RecordPrivacyRequest;
use App\Domain\Privacy\Operations\RunPrivacyExport;
use App\Domain\Privacy\Queries\OpenPrivacyRequest;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ExportPersonalData extends Command
{
    /** @var string */
    protected $signature = 'teme:privacy:export
        {email : The address the request is about}
        {--note= : Why, and how you checked who asked; never the address itself}
        {--no-reply : Don\'t email the file}';

    /** @var string */
    protected $description = 'Export the personal data this site holds for an address to one file, and email it there';

    public function handle(RecordPrivacyRequest $record, RunPrivacyExport $run, FailPrivacyRequest $fail, OpenPrivacyRequest $requests, ExportFile $files): int
    {
        // The operator, as teme:comments:erase-addresses is (operations.md, The operator).
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        $email = mb_strtolower(trim((string) $this->argument('email')));
        $id = $requests->for('export', $email);

        if ($id !== null) {
            $this->line(__('Continuing request :id, recorded :date. Its note is kept.', ['id' => $id, 'date' => $requests->summary($id)['created_at'] ?? '']));
        } else {
            $note = trim((string) $this->option('note'));

            if ($note === '') {
                $this->error(__('Give a note: why, and how you checked who asked. Never the address itself.'));

                return self::FAILURE;
            }

            try {
                $id = $record->execute(new RecordPrivacyRequestInput('export', $email, $note, ! $this->option('no-reply')));
            } catch (ValidationException $refused) {
                foreach ($refused->errors() as $messages) {
                    array_map($this->error(...), $messages);
                }

                return self::FAILURE;
            }
        }

        $this->line(__('Exporting the personal data held for request :id.', ['id' => $id]));

        try {
            do {
                $step = $run->execute(new RunPrivacyExportInput($id));
            } while (! $step->done);
        } catch (Throwable $failure) {
            $fail->execute(new FailPrivacyRequestInput($id, $failure::class.': '.$failure->getMessage()));
            $this->error(__('The request stopped: :reason (request :id).', ['reason' => $failure->getMessage(), 'id' => $id]));

            return self::FAILURE;
        }

        $request = $requests->summary($id);

        foreach ($request['results'] ?? [] as $entry) {
            $this->line(($entry['missing'] ?? false) === true
                ? __('A plugin that held data when the request started was no longer installed: :label.', ['label' => $entry['label']])
                : trans_choice(':label: :count item found.|:label: :count items found.', (int) $entry['found'], ['label' => $entry['label']]));
        }

        $path = (string) ($request['export_path'] ?? '');
        $this->line(__('Written to :path.', ['path' => $files->absolutePath($path)]));

        if (($request['reply'] ?? false) !== true) {
            $this->line(__('Not emailed (--no-reply).'));
        } elseif (! $files->fitsInEmail($path)) {
            $this->line(__('Not emailed: the file is larger than 20 MB. Send it yourself from :path.', ['path' => $files->absolutePath($path)]));
        } else {
            $this->line(__('Emailed to the address.'));
        }

        return self::SUCCESS;
    }
}

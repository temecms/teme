<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Privacy\Operations\FailPrivacyRequest;
use App\Domain\Privacy\Operations\Input\FailPrivacyRequestInput;
use App\Domain\Privacy\Operations\Input\RecordPrivacyRequestInput;
use App\Domain\Privacy\Operations\Input\RunPrivacyErasureInput;
use App\Domain\Privacy\Operations\RecordPrivacyRequest;
use App\Domain\Privacy\Operations\RunPrivacyErasure;
use App\Domain\Privacy\Queries\OpenPrivacyRequest;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * teme:privacy:erase — erases every piece of personal data the site holds
 * for an address, keeping what must be kept with its reason, as the
 * operator, who has checked who asked (I-3375). It asks first, since it
 * can't be undone. A run a killed process left is continued.
 */
final class ErasePersonalData extends Command
{
    /** @var string */
    protected $signature = 'teme:privacy:erase
        {email : The address the request is about}
        {--note= : Why, and how you checked who asked; never the address itself}
        {--no-reply : Don\'t email the person when it is done}
        {--force : Don\'t ask first}';

    /** @var string */
    protected $description = 'Erase the personal data this site holds for an address, saying what was kept and why';

    public function handle(RecordPrivacyRequest $record, RunPrivacyErasure $run, FailPrivacyRequest $fail, OpenPrivacyRequest $requests): int
    {
        // The operator, as teme:comments:erase-addresses is (operations.md, The operator).
        $this->laravel->instance(Elevation::class, new OperatorElevation);

        $email = mb_strtolower(trim((string) $this->argument('email')));
        $id = $requests->for('erase', $email);
        $note = trim((string) $this->option('note'));

        if ($id === null && $note === '') {
            $this->error(__('Give a note: why, and how you checked who asked. Never the address itself.'));

            return self::FAILURE;
        }

        // A run that can't ask takes no for an answer.
        if (! $this->option('force') && ! ($this->input->isInteractive() && $this->confirm(__("Erase every piece of personal data this site holds for this address? Comments keep their words. This can't be undone.")))) {
            $this->line(__('Nothing was erased.'));

            return self::FAILURE;
        }

        if ($id !== null) {
            $this->line(__('Continuing request :id, recorded :date. Its note is kept.', ['id' => $id, 'date' => $requests->summary($id)['created_at'] ?? '']));
        } else {
            try {
                $id = $record->execute(new RecordPrivacyRequestInput('erase', $email, $note, ! $this->option('no-reply')));
            } catch (ValidationException $refused) {
                foreach ($refused->errors() as $messages) {
                    array_map($this->error(...), $messages);
                }

                return self::FAILURE;
            }
        }

        try {
            do {
                $step = $run->execute(new RunPrivacyErasureInput($id));
            } while (! $step->done);
        } catch (Throwable $failure) {
            $fail->execute(new FailPrivacyRequestInput($id, $failure::class.': '.$failure->getMessage()));
            $this->error(__('The request stopped: :reason (request :id).', ['reason' => $failure->getMessage(), 'id' => $id]));

            return self::FAILURE;
        }

        $reasons = [];

        foreach ($requests->summary($id)['results'] ?? [] as $entry) {
            $this->line(__(':label: :removed removed, :retained kept.', ['label' => $entry['label'], 'removed' => $entry['removed'], 'retained' => $entry['retained']]));

            foreach ($entry['reasons'] as $reason) {
                $reasons[$reason] = true;
            }
        }

        foreach (array_keys($reasons) as $reason) {
            $this->line((string) $reason);
        }

        $this->line(__("The site's backups may still hold this data until they are replaced."));

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Admin\Tools\Backups;

use App\Domain\Backups\Operations\AddBackupDestination;
use App\Domain\Backups\Operations\ChangeBackupDestination;
use App\Domain\Backups\Operations\Input\AddBackupDestinationInput;
use App\Domain\Backups\Operations\Input\ChangeBackupDestinationInput;
use App\Domain\Backups\Operations\Input\SetUpBackupsInput;
use App\Domain\Backups\Operations\SetUpBackups;
use App\Domain\Backups\Queries\BackupSetup;
use App\Domain\Backups\RecoveryKey;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * An SFTP place's second step on Tools › Backups (I-2403): the public key to
 * add and the fingerprint to check, then making the place.
 */
final class SftpStepController extends Controller
{
    use WaitsForRestic;

    public function show(Request $request, SftpSteps $sftp, BackupSetup $setup): Response|RedirectResponse
    {
        $step = $sftp->current($request);

        if ($step === null) {
            // A step finished, or backups set up elsewhere meanwhile: the screen says what comes next.
            if ($setup->placesInUse()->isNotEmpty()) {
                return to_route('tools.backups.index');
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => __('That step has expired. Start again.')]);

            return to_route('tools.backups.setup');
        }

        return Inertia::render('tools/backups/sftp', [
            'purpose' => $step['purpose'],
            'placeId' => $step['place_id'],
            'name' => $step['name'],
            'host' => $step['host'],
            'port' => $step['port'],
            'user' => $step['user'],
            'path' => $step['path'],
            'publicKey' => $step['public_key'],
            'fingerprint' => $step['fingerprint'],
        ]);
    }

    public function store(Request $request, SftpSteps $sftp, SetUpBackups $setUp, AddBackupDestination $add, ChangeBackupDestination $change, RecoveryKeyPage $page): SymfonyResponse
    {
        $step = $sftp->current($request);

        if ($step === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('That step has expired. Start again.')]);

            return to_route('tools.backups.setup');
        }

        if (! $request->boolean('confirmed')) {
            throw ValidationException::withMessages(['confirmed' => __('Add the key and check the fingerprint first.')]);
        }

        $actorId = (string) $request->user()?->getAuthIdentifier();

        if ($step['purpose'] === SftpSteps::ADD) {
            // Read before anything runs: a key that isn't one is refused under its field.
            $key = RecoveryKey::fromTyped((string) $request->input('recovery_key'));
            $this->waitForRestic();

            $add->execute(new AddBackupDestinationInput($sftp->place($step), $key, $actorId));
            $sftp->forget($request);

            return self::told(__('Added :place.', ['place' => $step['name']]));
        }

        if ($step['purpose'] === SftpSteps::CHANGE) {
            $this->waitForRestic();

            $change->execute(new ChangeBackupDestinationInput(
                (string) $step['place_id'],
                $step['name'],
                ['host' => $step['host'], 'port' => $step['port'], 'user' => $step['user'], 'path' => $step['path'], 'host_key' => $step['host_key'], 'fingerprint' => $step['fingerprint']],
                null,
                $actorId,
            ));
            $sftp->forget($request);

            return self::told(__('Changed :place.', ['place' => $step['name']]));
        }

        $this->waitForRestic();

        $shown = $setUp->execute(new SetUpBackupsInput($sftp->place($step), (string) ($step['label'] ?? 'Recovery key'), (string) $request->user()?->getAuthIdentifier()));

        // Only once the place is made: a refusal leaves the step, to try again.
        $sftp->forget($request);

        return $page->show($request, $shown);
    }

    public function destroy(Request $request, SftpSteps $sftp): RedirectResponse
    {
        $step = $sftp->current($request);
        $sftp->forget($request);

        return match ($step['purpose'] ?? SftpSteps::SET_UP) {
            SftpSteps::ADD => to_route('tools.backups.places.create'),
            SftpSteps::CHANGE => to_route('tools.backups.places.edit', ['place' => $step['place_id']]),
            default => to_route('tools.backups.setup'),
        };
    }

    private static function told(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('tools.backups.index');
    }
}

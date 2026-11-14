<?php

declare(strict_types=1);

namespace App\Http\Admin\Tools\Backups;

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\Operations\AddBackupDestination;
use App\Domain\Backups\Operations\ChangeBackupDestination;
use App\Domain\Backups\Operations\Input\AddBackupDestinationInput;
use App\Domain\Backups\Operations\Input\ChangeBackupDestinationInput;
use App\Domain\Backups\Operations\Input\RemoveBackupDestinationInput;
use App\Domain\Backups\Operations\Input\SetUpBackupsInput;
use App\Domain\Backups\Operations\Input\TestBackupDestinationInput;
use App\Domain\Backups\Operations\RemoveBackupDestination;
use App\Domain\Backups\Operations\TestBackupDestination;
use App\Domain\Backups\Queries\BackupSetup;
use App\Domain\Backups\Queries\BackupsScreen;
use App\Domain\Backups\Queries\PlaceStorage;
use App\Domain\Backups\RecoveryKey;
use App\Domain\Support\Security\Elevation;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The places backups are kept, on Tools › Backups (I-360): testing, adding,
 * changing and removing one, and measuring what one holds, through 1.12c's
 * Operations.
 */
final class BackupPlacesController extends Controller
{
    use WaitsForRestic;

    public function test(Request $request, TestBackupDestination $test, string $place): RedirectResponse
    {
        $this->waitForRestic();

        try {
            $answer = $test->execute(new TestBackupDestinationInput($place, (string) $request->user()?->getAuthIdentifier()));
        } catch (ValidationException $refused) {
            return self::told('error', self::first($refused));
        }

        return self::told($answer->opened ? 'success' : 'error', $answer->sentence);
    }

    public function destroy(Request $request, RemoveBackupDestination $remove, BackupSetup $setup, BackupsScreen $screen, string $place): RedirectResponse
    {
        $startingAgain = ! $setup->isFinished();
        $name = $screen->placeNames([$place])[0] ?? '';

        try {
            $remove->execute(new RemoveBackupDestinationInput($place, (string) $request->user()?->getAuthIdentifier()));
        } catch (ValidationException $refused) {
            return self::told('error', self::first($refused));
        }

        return self::told('success', $startingAgain
            ? __('Removed :place. Set backups up again when you’re ready.', ['place' => $name])
            : __('Removed :place. Its backups are still there.', ['place' => $name]));
    }

    public function create(BackupsScreen $screen): Response
    {
        return Inertia::render('tools/backups/place', $screen->placeForm(null));
    }

    public function store(Request $request, AddBackupDestination $add, SftpSteps $sftp): SymfonyResponse
    {
        $sftp->forget($request);
        $kind = PlaceForm::kind($request);

        if ($kind === BackupDestinationKind::Sftp) {
            // The password before ssh-keyscan runs or a key pair is made (I-2403).
            app(Elevation::class)->ensure(new SetUpBackupsInput(null, actorId: (string) $request->user()?->getAuthIdentifier()));

            return $sftp->begin($request, SftpSteps::ADD, null, PlaceForm::sftpFields($request), null);
        }

        // Read before anything runs: a key that isn't one is refused under its field.
        $key = RecoveryKey::fromTyped((string) $request->input('recovery_key'));
        $this->waitForRestic();

        $add->execute(new AddBackupDestinationInput(PlaceForm::place($kind, $request), $key, (string) $request->user()?->getAuthIdentifier()));

        // The keys the new place lacks show on the screen, from the tables (I-2402).
        return self::told('success', __('Added :place.', ['place' => trim((string) $request->input('name'))]));
    }

    public function edit(BackupsScreen $screen, string $place): Response|RedirectResponse
    {
        $form = $screen->placeForm($place);

        if ($form['place'] === null) {
            return self::told('error', __('There’s no such place.'));
        }

        return Inertia::render('tools/backups/place', $form);
    }

    public function update(Request $request, ChangeBackupDestination $change, BackupsScreen $screen, SftpSteps $sftp, string $place): SymfonyResponse
    {
        $current = $screen->placeForm($place)['place'] ?? throw ValidationException::withMessages(['place' => __('There’s no such place.')]);
        $sftp->forget($request);

        if ($current['kind'] === BackupDestinationKind::Sftp->value) {
            $fields = PlaceForm::sftpFields($request);

            // A new server needs its fingerprint confirmed first (I-2403).
            if ($fields['host'] !== $current['host'] || $fields['port'] !== $current['port']) {
                app(Elevation::class)->ensure(new SetUpBackupsInput(null, actorId: (string) $request->user()?->getAuthIdentifier()));

                return $sftp->begin($request, SftpSteps::CHANGE, $place, $fields, null, (string) $current['publicKey']);
            }
        }

        $credentials = PlaceForm::changedCredentials($request, $current['kind']);
        $this->waitForRestic();

        $change->execute(new ChangeBackupDestinationInput($place, trim((string) $request->input('name')), PlaceForm::changedLocation($request, $current['kind']), $credentials, (string) $request->user()?->getAuthIdentifier()));

        return self::told('success', __('Changed :place.', ['place' => trim((string) $request->input('name'))]));
    }

    public function storage(PlaceStorage $storage, string $place): JsonResponse
    {
        $this->waitForRestic();

        return response()->json($storage->measure($place));
    }

    private static function told(string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return to_route('tools.backups.index');
    }

    private static function first(ValidationException $refused): string
    {
        return (string) (collect($refused->errors())->flatten()->first() ?? $refused->getMessage());
    }
}

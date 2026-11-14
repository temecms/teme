<?php

declare(strict_types=1);

namespace App\Http\Admin\Tools\Backups;

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\Operations\Input\SetUpBackupsInput;
use App\Domain\Backups\Operations\SetUpBackups;
use App\Domain\Backups\Queries\BackupSetup;
use App\Domain\Support\Security\Elevation;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Setting backups up from Tools › Backups (I-2401): the setup page, and the
 * request that makes the first place and shows the recovery key, once.
 */
final class BackupSetupController extends Controller
{
    use WaitsForRestic;

    public function create(Request $request, BackupSetup $setup, SftpSteps $sftp): Response|RedirectResponse
    {
        // Forgets an SFTP step older than an hour (I-2403).
        $sftp->current($request);

        // Set up, or half set up: the screen says what comes next.
        if ($setup->placesInUse()->isNotEmpty()) {
            return to_route('tools.backups.index');
        }

        return Inertia::render('tools/backups/set-up', ['suggestedFolder' => BackupSetup::suggestedFolder()]);
    }

    public function store(Request $request, SetUpBackups $setUp, BackupSetup $setup, SftpSteps $sftp, RecoveryKeyPage $page): SymfonyResponse
    {
        $actorId = (string) $request->user()?->getAuthIdentifier();

        // A key page the browser can't keep is a key shown to nobody: refused before anything runs (I-2401).
        RecoveryKeyPage::ensureSecure($request);

        // Another place form sent: an SFTP step left open goes (I-2403).
        $sftp->forget($request);

        // Half set up: a new key for the place already made; the old key is swept (I-2401).
        if ($setup->unfinishedPlace() !== null) {
            $this->waitForRestic();

            return $page->show($request, $setUp->execute(new SetUpBackupsInput(null, actorId: $actorId)));
        }

        $kind = PlaceForm::kind($request);
        $label = trim((string) $request->input('label', 'Recovery key'));

        if ($kind === BackupDestinationKind::Sftp) {
            // The password before ssh-keyscan runs or a key pair is made, as the Operation will ask it (I-2403).
            app(Elevation::class)->ensure(new SetUpBackupsInput(null, actorId: $actorId));

            return $sftp->begin($request, SftpSteps::SET_UP, null, PlaceForm::sftpFields($request), $label);
        }

        $this->waitForRestic();

        return $page->show($request, $setUp->execute(new SetUpBackupsInput(PlaceForm::place($kind, $request), $label, $actorId)));
    }
}

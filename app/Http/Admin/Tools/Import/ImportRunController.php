<?php

/**
 * Starting, stopping, carrying on and catching up a site's import. A start asks for the person's password first (I-1707), and keeps their choices across the confirmation.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Tools\Import;

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Operations\ContinueImportRun;
use App\Domain\Import\Operations\Input\ContinueImportRunInput;
use App\Domain\Import\Operations\Input\StartImportRunInput;
use App\Domain\Import\Operations\Input\StopImportRunInput;
use App\Domain\Import\Operations\StartImportRun;
use App\Domain\Import\Operations\StopImportRun;
use App\Domain\Import\Planning\ImportAnalysis;
use App\Domain\Import\Planning\ImportChoices;
use App\Domain\Import\Queries\ImportScreen;
use App\Domain\Import\Tracking\ImportRunJobs;
use App\Domain\Jobs\Operations\CancelJob;
use App\Domain\Jobs\Operations\Input\CancelJobInput;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class ImportRunController extends Controller
{
    /** The session key the choices are kept under across the password's confirmation, followed by the site's ID. */
    public const string REMEMBERED = 'import.choices.';

    public function start(Request $request, string $source, ImportScreen $screen, ImportAnalysis $analysis, ImportChoices $choices, StartImportRun $start): RedirectResponse
    {
        $site = $screen->site($source);
        abort_if($site === null, 404);
        $kept = $analysis->kept($source);

        if ($kept === null || ! $kept->reported) {
            return back()->withErrors(['choices' => 'Analyze the site before you start: Teme needs its report.']);
        }

        $fileId = $site['file']['id'] ?? null;
        $chosen = $fileId === null ? $choices->validated($request->all(), $kept) : $choices->validatedFile($request->all(), $kept, $fileId);

        try {
            // The site's export file when it reads from one now, else its companion.
            $start->execute(new StartImportRunInput($source, ImportRunKind::Full, ['pause_ms' => $chosen['pauseMs']], $chosen['choices'], self::actor($request), $fileId));
        } catch (ElevationRequiredException $ask) {
            // Kept across the password's confirmation, so nothing is chosen twice.
            $request->session()->put(self::REMEMBERED.$source, [...$chosen['choices'], 'pause' => $chosen['pauseMs']]);

            throw $ask;
        } catch (ValidationException $refused) {
            return self::told((string) $refused->validator->errors()->first(), 'error');
        }

        $request->session()->forget(self::REMEMBERED.$source);

        return self::told("Started importing {$site['host']}.");
    }

    public function catchUp(Request $request, string $source, ImportScreen $screen, StartImportRun $start): RedirectResponse
    {
        $site = $screen->site($source);
        abort_if($site === null, 404);
        $with = $screen->catchUpWith($source);

        try {
            $start->execute(new StartImportRunInput($source, ImportRunKind::CatchUp, $with['options'] ?? [], $with['choices'] ?? [], self::actor($request)));
        } catch (ValidationException $refused) {
            return self::told((string) $refused->validator->errors()->first(), 'error');
        }

        return self::told("Started a catch-up of {$site['host']}.");
    }

    public function stop(Request $request, string $run, ImportScreen $screen, ImportRunJobs $tracking, CancelJob $cancel, StopImportRun $stop): RedirectResponse
    {
        $source = $screen->sourceOf($run);
        abort_if($source === null, 404);
        $job = $tracking->liveJob($run);

        try {
            // Through its job, whose kind stops the run as whoever asked; directly when it has none.
            if ($job === null) {
                $stop->execute(new StopImportRunInput($run, self::actor($request)));
            } else {
                $cancel->execute(new CancelJobInput($job, self::actor($request)));
            }
        } catch (ValidationException $refused) {
            return self::told((string) $refused->validator->errors()->first(), 'error');
        }

        return self::told('Stopped the import of '.($screen->site($source)['host'] ?? '').'. Carry it on whenever you like: it picks up where it stopped.');
    }

    public function carryOn(Request $request, string $run, ImportScreen $screen, ContinueImportRun $continue): RedirectResponse
    {
        $source = $screen->sourceOf($run);
        abort_if($source === null, 404);

        try {
            $continue->execute(new ContinueImportRunInput($run, self::actor($request)));
        } catch (ValidationException $refused) {
            return self::told((string) $refused->validator->errors()->first(), 'error');
        }

        return self::told('Carrying on the import of '.($screen->site($source)['host'] ?? '').'.');
    }

    /**
     * Who is asking, as an Operation's input carries them.
     */
    private static function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }

    /**
     * Back to the page the person was on, with a toast.
     */
    private static function told(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return redirect()->back(fallback: route('tools.importer.index'));
    }
}

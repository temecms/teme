<?php

/**
 * The recovery keys on Tools › Backups (I-2401): making, replacing, checking
 * and extending them, and the groups typed back to finish one, which answers
 * JSON so the key page can stay open.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Tools\Backups;

use App\Domain\Backups\BackupWords;
use App\Domain\Backups\Operations\AddRecoveryKey;
use App\Domain\Backups\Operations\CheckRecoveryKey;
use App\Domain\Backups\Operations\ConfirmRecoveryKey;
use App\Domain\Backups\Operations\ExtendRecoveryKey;
use App\Domain\Backups\Operations\Input\AddRecoveryKeyInput;
use App\Domain\Backups\Operations\Input\CheckRecoveryKeyInput;
use App\Domain\Backups\Operations\Input\ConfirmRecoveryKeyInput;
use App\Domain\Backups\Operations\Input\ExtendRecoveryKeyInput;
use App\Domain\Backups\Operations\Input\ReplaceRecoveryKeyInput;
use App\Domain\Backups\Operations\ReplaceRecoveryKey;
use App\Domain\Backups\Queries\BackupsScreen;
use App\Domain\Backups\RecoveryKey;
use App\Domain\Backups\RecoveryKeyChallengeId;
use App\Domain\Backups\RecoveryKeyPurpose;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class RecoveryKeyController extends Controller
{
    use WaitsForRestic;

    public function index(): RedirectResponse
    {
        return to_route('tools.backups.index');
    }

    public function create(): Response
    {
        return Inertia::render('tools/backups/new-key');
    }

    public function store(Request $request, AddRecoveryKey $add, ReplaceRecoveryKey $replace, RecoveryKeyPage $page): SymfonyResponse
    {
        // A key page the browser can't keep is a key shown to nobody: refused before anything runs (I-2401).
        RecoveryKeyPage::ensureSecure($request);
        $this->waitForRestic();

        $label = trim((string) $request->input('label'));
        $actorId = (string) $request->user()?->getAuthIdentifier();

        return $page->show($request, $request->boolean('replace')
            ? $replace->execute(new ReplaceRecoveryKeyInput($label, $actorId))
            : $add->execute(new AddRecoveryKeyInput($label, $actorId)));
    }

    public function extend(Request $request, ExtendRecoveryKey $extend): RedirectResponse
    {
        $key = RecoveryKey::fromTyped((string) $request->input('recovery_key'));
        $this->waitForRestic();

        $result = $extend->execute(new ExtendRecoveryKeyInput($key, (string) $request->user()?->getAuthIdentifier()));
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$result['label']} now opens the backups at ".BackupWords::names($result['places']).' too.']);

        return to_route('tools.backups.index');
    }

    public function check(Request $request, CheckRecoveryKey $check): JsonResponse
    {
        $key = RecoveryKey::fromTyped((string) $request->input('recovery_key'));
        $this->waitForRestic();

        $answer = $check->execute(new CheckRecoveryKeyInput($key, (string) $request->user()?->getAuthIdentifier()));

        return response()->json(['label' => $answer->label, 'opens' => $answer->opens, 'doesNotOpen' => $answer->doesNotOpen]);
    }

    public function confirm(Request $request, ConfirmRecoveryKey $confirm, BackupsScreen $screen, BackupWords $words): JsonResponse
    {
        $this->waitForRestic();

        $stale = 'This key was replaced, or the places changed, before it was confirmed. Start again to see a new key.';
        $challenge = RecoveryKeyPage::challenge($request) ?? throw ValidationException::withMessages(['groups' => $stale]);

        // The page names the key it showed; another tab may have made a newer one since (I-2401).
        if (! hash_equals(RecoveryKeyChallengeId::of($challenge), (string) $request->input('challenge'))) {
            throw ValidationException::withMessages(['groups' => $stale]);
        }

        $typed = (array) $request->input('groups', []);
        $groups = [];

        foreach ($challenge->positions as $position) {
            $groups[$position] = (string) ($typed[$position] ?? '');
        }

        // Refusals pass through: 422 under groups; a lapsed password, 423 (bootstrap/app.php).
        $confirmed = $confirm->execute(new ConfirmRecoveryKeyInput($challenge, $groups, (string) $request->user()?->getAuthIdentifier()));
        $places = $screen->placeNames(array_keys($challenge->pending));

        RecoveryKeyPage::forget($request);

        $message = match ($challenge->purpose) {
            RecoveryKeyPurpose::SetUp => "Backups are set up at {$places[0]}. The first backup starts {$words->next(CarbonImmutable::now())}.",
            RecoveryKeyPurpose::Add => "{$confirmed->label} now opens the backups at every place.",
            RecoveryKeyPurpose::Replace => "Replaced {$confirmed->label}. The old key no longer opens the backups.",
        };

        foreach ($confirmed->oldKeyKeptAt as $place) {
            $message .= " The old key still opens {$place}, which didn’t answer. Replace {$confirmed->label} again once it does.";
        }

        Inertia::flash('toast', ['type' => $confirmed->oldKeyKeptAt === [] ? 'success' : 'warning', 'message' => $message]);

        return response()->json(['redirect' => route('tools.backups.index', absolute: false)]);
    }
}

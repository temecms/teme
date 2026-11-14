<?php

declare(strict_types=1);

namespace App\Http\Admin\Tools\Backups;

use App\Domain\Backups\Queries\BackupsScreen;
use App\Domain\Backups\RecoveryKeyChallenge;
use App\Domain\Backups\RecoveryKeyChallengeId;
use App\Domain\Backups\ShownRecoveryKey;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Teme\Contracts\Settings\SettingsReader;

/**
 * The page that shows a new recovery key (I-2401): the answer to the request
 * that made the key, and nothing else. Its groups go to that one response,
 * which no cache keeps and the browser's history holds only encrypted; the
 * session keeps the challenge, which holds no part of the key.
 */
final readonly class RecoveryKeyPage
{
    /** Where the challenge waits until two groups are typed back. */
    public const string CHALLENGE = 'backups.challenge';

    public function __construct(
        private BackupsScreen $screen,
        private SettingsReader $settings,
    ) {}

    public function show(Request $request, ShownRecoveryKey $shown): Response
    {
        $request->session()->put(self::CHALLENGE, $shown->challenge->toArray());

        Inertia::encryptHistory();

        $response = Inertia::render('tools/backups/recovery-key', [
            'purpose' => $shown->challenge->purpose->value,
            'label' => $shown->challenge->label,
            'groups' => $shown->key->groups(),
            'positions' => $shown->challenge->positions,
            'places' => $this->screen->placeNames(array_keys($shown->challenge->pending)),
            'site' => $this->settings->string('general', 'site_title'),
            'madeOn' => CarbonImmutable::now()->setTimezone($this->settings->string('general', 'timezone'))->format('j F Y'),
            'challengeId' => RecoveryKeyChallengeId::of($shown->challenge),
        ])->toResponse($request);

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * Refuses, before any key is made, a request the browser can't keep a
     * key page for: history encryption needs a secure context (I-2401).
     *
     * @throws ValidationException
     */
    public static function ensureSecure(Request $request): void
    {
        if (! $request->secure() && ! in_array($request->getHost(), ['localhost', '127.0.0.1', '[::1]'], true)) {
            throw ValidationException::withMessages(['place' => 'Set backups up over HTTPS: a recovery key is never sent over a plain connection.']);
        }
    }

    /**
     * The challenge the session waits on, or null when there is none or it isn't one.
     */
    public static function challenge(Request $request): ?RecoveryKeyChallenge
    {
        $stored = $request->session()->get(self::CHALLENGE);

        if (! is_array($stored)) {
            return null;
        }

        try {
            return RecoveryKeyChallenge::fromArray($stored);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Lets the challenge go, and has Inertia throw away the key its history was encrypted with.
     */
    public static function forget(Request $request): void
    {
        $request->session()->forget(self::CHALLENGE);
        Inertia::clearHistory();
    }
}

<?php

/**
 * An SFTP place's two steps (I-2403): the server's fingerprint read and a
 * key pair made in the first, kept in the session until the person has added
 * the public half and confirmed the fingerprint. The private half is kept
 * encrypted under APP_KEY, since sessions are stored rows; it never reaches
 * the page.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Tools\Backups;

use App\Domain\Backups\NewPlace;
use App\Domain\Backups\Tools\SftpKeyPair;
use App\Domain\Backups\Tools\SshHostKey;
use App\Domain\Backups\Tools\SshKeyscan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use LogicException;

final readonly class SftpSteps
{
    public const string SESSION = 'backups.sftp';

    /** How long a step waits before it must be started again. */
    public const int MINUTES = 60;

    public const string SET_UP = 'set-up';

    public const string ADD = 'add';

    public const string CHANGE = 'change';

    public function __construct(private SshKeyscan $keyscan) {}

    /**
     * Reads the server's host key, makes the new place's key pair (a changed
     * place keeps its own and passes its public key), checks the place, and
     * keeps the step for the second page.
     *
     * @param  array{name: string, host: string, port: int, user: string, path: string}  $fields
     *
     * @throws ValidationException
     */
    public function begin(Request $request, string $purpose, ?string $placeId, array $fields, ?string $label, ?string $publicKey = null): RedirectResponse
    {
        // Refuses a host or port that ssh's configuration couldn't hold, before running anything.
        $hostKey = $this->keyscan->hostKey($fields['host'], $fields['port']);
        $pair = $purpose === self::CHANGE ? null : SftpKeyPair::generate();

        if ($pair !== null) {
            $problems = NewPlace::sftp($fields['name'], $fields['host'], $fields['port'], $fields['user'], $fields['path'], $pair, $hostKey)->problems();

            if ($problems !== []) {
                throw ValidationException::withMessages($problems);
            }
        }

        $request->session()->put(self::SESSION, [
            'purpose' => $purpose,
            'place_id' => $placeId,
            ...$fields,
            'label' => $label,
            'host_key' => $hostKey->line(),
            'fingerprint' => $hostKey->fingerprint(),
            'public_key' => $pair?->publicKey() ?? (string) $publicKey,
            'private_key' => $pair === null ? null : Crypt::encryptString($pair->privateKey()),
            'made_at' => Date::now()->getTimestamp(),
        ]);

        return to_route('tools.backups.sftp');
    }

    /**
     * The step waiting in the session, or null when there is none, it isn't
     * one, or it is older than an hour.
     *
     * @return array{purpose: string, place_id: string|null, name: string, host: string, port: int, user: string, path: string, label: string|null, host_key: string, fingerprint: string, public_key: string, private_key: string|null, made_at: int}|null
     */
    public function current(Request $request): ?array
    {
        $step = $request->session()->get(self::SESSION);

        if (! is_array($step) || ! isset($step['purpose'], $step['host'], $step['host_key'], $step['made_at']) || ! is_int($step['made_at'])) {
            return null;
        }

        if (Date::now()->getTimestamp() - $step['made_at'] > self::MINUTES * 60) {
            $this->forget($request);

            return null;
        }

        /** @var array{purpose: string, place_id: string|null, name: string, host: string, port: int, user: string, path: string, label: string|null, host_key: string, fingerprint: string, public_key: string, private_key: string|null, made_at: int} $step */
        return $step;
    }

    /**
     * The new place the step describes, its private key decrypted for this call only.
     *
     * @param  array{name: string, host: string, port: int, user: string, path: string, host_key: string, private_key: string|null}  $step
     */
    public function place(array $step): NewPlace
    {
        if ($step['private_key'] === null) {
            throw new LogicException('The SFTP step holds no private key for a change.');
        }

        return NewPlace::sftp(
            $step['name'], $step['host'], $step['port'], $step['user'], $step['path'],
            SftpKeyPair::fromPrivateKey(Crypt::decryptString($step['private_key'])),
            SshHostKey::fromLine($step['host_key']),
        );
    }

    public function forget(Request $request): void
    {
        $request->session()->forget(self::SESSION);
    }
}

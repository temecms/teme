<?php

declare(strict_types=1);

namespace App\Domain\Backups\Tools;

use App\Domain\Backups\NewPlace;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\Process\Exception\RuntimeException;

/**
 * Reads an SSH server's host key with ssh-keyscan, so a person can confirm
 * its fingerprint before Teme trusts it (I-2403).
 */
final readonly class SshKeyscan
{
    /** How long the process may run, in seconds; ssh-keyscan itself gives up after 10 of silence. */
    public const int TIMEOUT = 15;

    /**
     * The server's host key, the most preferred type it offers.
     *
     * @throws ValidationException
     */
    public function hostKey(string $host, int $port): SshHostKey
    {
        if (preg_match(NewPlace::HOST, $host) !== 1) {
            throw ValidationException::withMessages(['host' => 'Give the server’s name or IP address, such as backup.example.com.']);
        }

        if ($port < 1 || $port > 65535) {
            throw ValidationException::withMessages(['port' => 'A port is a number from 1 to 65535.']);
        }

        $notThere = ValidationException::withMessages(['host' => 'Teme couldn’t run ssh-keyscan. Install OpenSSH’s client on this server to keep backups over SFTP.']);

        try {
            $result = Process::env(ChildEnvironment::withoutInherited())
                ->timeout(self::TIMEOUT)
                ->run(['ssh-keyscan', '-T', '10', '-p', (string) $port, $host]);
        } catch (ProcessTimedOutException) {
            throw $this->noAnswer($host, $port);
        } catch (RuntimeException) {
            throw $notThere;
        }

        if ($result->exitCode() === 127) {
            throw $notThere;
        }

        $found = [];

        foreach (preg_split('/\R/', $result->output()) ?: [] as $line) {
            if (trim($line) === '' || str_starts_with(trim($line), '#')) {
                continue;
            }

            try {
                $key = SshHostKey::fromLine($line);
            } catch (InvalidArgumentException) {
                continue;
            }

            $found[$key->type] ??= $key;
        }

        foreach (SshHostKey::PREFERRED as $type) {
            if (isset($found[$type])) {
                return $found[$type];
            }
        }

        throw $this->noAnswer($host, $port);
    }

    private function noAnswer(string $host, int $port): ValidationException
    {
        return ValidationException::withMessages(['host' => "{$host} didn’t answer on port {$port}, so Teme couldn’t read its fingerprint."]);
    }
}

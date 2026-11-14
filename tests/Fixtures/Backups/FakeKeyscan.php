<?php

declare(strict_types=1);

namespace Tests\Fixtures\Backups;

use App\Domain\Backups\Tools\SftpKeyPair;
use App\Domain\Backups\Tools\SshHostKey;
use Illuminate\Support\Facades\Process;

/**
 * ssh-keyscan answered without a network: one Ed25519 host key, made for the
 * test. Bind it before WatchedProcesses::asRestic(), which replaces every fake.
 */
final class FakeKeyscan
{
    /**
     * Answers ssh-keyscan with a fresh host key for $host, and returns that key.
     */
    public static function answering(string $host = 'backup.example.com'): SshHostKey
    {
        [$type, $blob] = explode(' ', SftpKeyPair::generate()->publicKey());

        Process::fake(['*ssh-keyscan*' => Process::result(output: "{$host} {$type} {$blob}\n")]);

        return new SshHostKey($type, $blob);
    }
}

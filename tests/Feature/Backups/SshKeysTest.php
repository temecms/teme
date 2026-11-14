<?php

declare(strict_types=1);

use App\Domain\Backups\Tools\SftpKeyPair;
use App\Domain\Backups\Tools\SshHostKey;
use App\Domain\Backups\Tools\SshKeyscan;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;

// GitHub's published Ed25519 host key, and the fingerprint ssh-keygen -l prints for it.
const SSH_GITHUB_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl';
const SSH_GITHUB_FINGERPRINT = 'SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU';

it('makes an Ed25519 pair in OpenSSH’s own format, and reads it back (I-2403)', function (): void {
    $pair = SftpKeyPair::generate();
    $private = $pair->privateKey();

    expect($private)->toStartWith("-----BEGIN OPENSSH PRIVATE KEY-----\n")
        ->and($private)->toEndWith("\n-----END OPENSSH PRIVATE KEY-----\n")
        ->and($pair->publicKey())->toMatch('#^ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI[A-Za-z0-9+/]{43} teme-backup$#')
        ->and(SftpKeyPair::fromPrivateKey($private)->publicKey())->toBe($pair->publicKey())
        ->and($pair->privateKey())->toBe($private)
        ->and(SftpKeyPair::generate()->publicKey())->not->toBe($pair->publicKey());
});

it('refuses a private key it didn’t make', function (string $text): void {
    expect(fn () => SftpKeyPair::fromPrivateKey($text))->toThrow(InvalidArgumentException::class, 'That isn’t a private key Teme made.');
})->with([
    'nothing' => [''],
    'not base64' => ["-----BEGIN OPENSSH PRIVATE KEY-----\n!!!!\n-----END OPENSSH PRIVATE KEY-----\n"],
    'a PEM key' => ["-----BEGIN PRIVATE KEY-----\nMC4CAQAwBQYDK2VwBCIEIFj8\n-----END PRIVATE KEY-----\n"],
    'cut short' => fn (): string => substr(SftpKeyPair::generate()->privateKey(), 0, 200)."\n-----END OPENSSH PRIVATE KEY-----\n",
]);

it('never serializes a key pair, nor shows its private half when dumped', function (): void {
    $pair = SftpKeyPair::generate();
    $body = explode("\n", $pair->privateKey())[1];

    ob_start();
    var_dump($pair);
    $dumped = (string) ob_get_clean();

    expect(fn () => serialize($pair))->toThrow(LogicException::class, 'A key pair is never serialized.')
        ->and(print_r($pair, true))->not->toContain($body)
        ->and($dumped)->not->toContain($body)
        ->and(json_encode($pair))->toBe('{}');
});

it('reads a host key and gives the fingerprint ssh-keygen gives', function (): void {
    $plain = SshHostKey::fromLine(SSH_GITHUB_KEY.' a comment');
    $scanned = SshHostKey::fromLine('github.com '.SSH_GITHUB_KEY);

    expect($plain->type)->toBe('ssh-ed25519')
        ->and($plain->line())->toBe(SSH_GITHUB_KEY)
        ->and($plain->fingerprint())->toBe(SSH_GITHUB_FINGERPRINT)
        ->and($scanned)->toEqual($plain)
        ->and(fn () => SshHostKey::fromLine('ssh-ed25519 not*base64'))->toThrow(InvalidArgumentException::class, 'That isn’t an SSH public key.')
        ->and(fn () => SshHostKey::fromLine('ssh-rsa '.explode(' ', SSH_GITHUB_KEY)[1]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => SshHostKey::fromLine('ssh-dss AAAAB3NzaC1kc3M='))->toThrow(InvalidArgumentException::class);
});

it('prefers an Ed25519 key among ssh-keyscan’s answers', function (): void {
    $rsa = 'ssh-rsa '.base64_encode(pack('N', 7).'ssh-rsa'.random_bytes(40));

    Process::fake(['*' => Process::result(output: "# backup.example.com:2222 SSH-2.0-OpenSSH_9.6\nbackup.example.com {$rsa}\nbackup.example.com ".SSH_GITHUB_KEY."\n")]);

    $key = app(SshKeyscan::class)->hostKey('backup.example.com', 2222);

    expect($key->line())->toBe(SSH_GITHUB_KEY);

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['ssh-keyscan', '-T', '10', '-p', '2222', 'backup.example.com']
        && $process->timeout === SshKeyscan::TIMEOUT);
});

it('says when the server gives no key, and never scans a host that isn’t one', function (): void {
    Process::fake(['*' => Process::result(output: '', exitCode: 1)]);

    expect(fn () => app(SshKeyscan::class)->hostKey('backup.example.com', 22))
        ->toThrow(ValidationException::class, 'backup.example.com didn’t answer on port 22, so Teme couldn’t read its fingerprint.')
        ->and(fn () => app(SshKeyscan::class)->hostKey('-oProxyCommand=touch', 22))
        ->toThrow(ValidationException::class, 'Give the server’s name or IP address, such as backup.example.com.')
        ->and(fn () => app(SshKeyscan::class)->hostKey('backup.example.com', 70000))
        ->toThrow(ValidationException::class, 'A port is a number from 1 to 65535.');

    Process::assertRanTimes(fn (PendingProcess $process): bool => true, 1);
});

it('says when ssh-keyscan isn’t there to run', function (): void {
    Process::fake(['*' => Process::result(output: '', exitCode: 127)]);

    expect(fn () => app(SshKeyscan::class)->hostKey('backup.example.com', 22))
        ->toThrow(ValidationException::class, 'Teme couldn’t run ssh-keyscan. Install OpenSSH’s client on this server to keep backups over SFTP.');
});

<?php

/**
 * Reads a place and its key from a restore command's options, every secret from a file or a hidden prompt.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Domain\Backups\NewPlace;
use App\Domain\Backups\Restore\RestoreRequest;
use App\Domain\Backups\Tools\SftpKeyPair;
use App\Domain\Backups\Tools\SshHostKey;
use App\Domain\Backups\Tools\SshKeyscan;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

trait NamesRestorePlace
{
    /** The options a command that names a place on a new server adds to its signature. */
    private const string PLACE_OPTIONS = ' {--kind= : folder, s3 or sftp} {--path= : A folder place\'s path} {--endpoint=} {--bucket=} {--folder= : The folder in the bucket, or on the SFTP server} {--region=} {--secret-file= : Two lines: the access key ID, then the secret access key} {--host=} {--port=22} {--user=} {--host-key= : The SFTP server\'s host key line} {--private-key-file=} {--key-file= : A file holding the recovery key or the machine key}';

    /**
     * The place named with --kind and the key that opens it; both null when
     * none is named. False after printing why it can't be read.
     *
     * @return array{from: NewPlace|null, key: string|null}|false
     */
    protected function namedPlace(SshKeyscan $keyscan, bool $force): array|false
    {
        $kind = $this->option('kind');

        if ($kind === null) {
            return ['from' => null, 'key' => null];
        }

        try {
            $from = match ($kind) {
                'folder' => NewPlace::folder('the place you named', (string) $this->option('path')),
                's3' => $this->s3Place(),
                'sftp' => $this->sftpPlace($keyscan, $force),
                default => throw new InvalidArgumentException('Name the place’s kind: folder, s3 or sftp.'),
            };

            if ($from === null) {
                $this->error('Nothing was restored.');

                return false;
            }

            return ['from' => $from, 'key' => RestoreRequest::keyFrom($this->typedKey())];
        } catch (ValidationException $invalid) {
            foreach ($invalid->errors() as $sentences) {
                foreach ($sentences as $sentence) {
                    $this->error($sentence);
                }
            }
        } catch (InvalidArgumentException $invalid) {
            $this->error($invalid->getMessage());
        }

        return false;
    }

    private function s3Place(): NewPlace
    {
        $file = $this->option('secret-file');

        if ($file !== null) {
            $lines = preg_split('/\R/', trim($this->readFile((string) $file))) ?: [];

            if (count($lines) < 2 || trim($lines[0]) === '' || $lines[1] === '') {
                throw new InvalidArgumentException("Give the access key ID on the first line of {$file}, and the secret access key on the second.");
            }

            [$id, $secret] = [trim($lines[0]), trim($lines[1])];
        } else {
            $id = (string) $this->secret('The access key ID:');
            $secret = (string) $this->secret('The secret access key:');
        }

        return NewPlace::s3('the place you named', (string) $this->option('endpoint'), (string) $this->option('bucket'), $this->option('folder'), $this->option('region'), $id, $secret);
    }

    /**
     * The host key is settled first, so nothing is read, and no server reached, before a person has said it is theirs.
     */
    private function sftpPlace(SshKeyscan $keyscan, bool $force): ?NewPlace
    {
        $host = (string) $this->option('host');
        $port = (int) $this->option('port');

        if ($this->option('host-key') !== null) {
            $hostKey = SshHostKey::fromLine((string) $this->option('host-key'));
        } elseif ($force || ! $this->input->isInteractive()) {
            throw new InvalidArgumentException('Name the server’s host key with --host-key when there’s no one to confirm its fingerprint.');
        } else {
            $hostKey = $keyscan->hostKey($host, $port);

            if (! $this->confirm("The server answers with the key whose fingerprint is {$hostKey->fingerprint()}. Is that the server you mean?", false)) {
                return null;
            }
        }

        try {
            $pair = SftpKeyPair::fromPrivateKey($this->readFile((string) $this->option('private-key-file')));
        } catch (InvalidArgumentException $notAKey) {
            throw new InvalidArgumentException($notAKey->getMessage().' Give an unencrypted Ed25519 key the server accepts.');
        }

        return NewPlace::sftp('the place you named', $host, $port, (string) $this->option('user'), (string) $this->option('folder'), $pair, $hostKey);
    }

    private function typedKey(): string
    {
        $file = $this->option('key-file');

        return $file !== null ? $this->readFile((string) $file) : (string) $this->secret('The recovery key, or the machine key:');
    }

    private function readFile(string $file): string
    {
        $contents = is_file($file) ? @file_get_contents($file) : false;

        if ($contents === false) {
            throw new InvalidArgumentException("Teme can’t read {$file}.");
        }

        return trim($contents);
    }
}

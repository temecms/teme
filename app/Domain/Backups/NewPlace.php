<?php

/**
 * A place to keep backups as a person describes it, before it exists (I-360):
 * a folder on this server, S3-compatible storage, or an SFTP server, its
 * location never a secret and its credentials hidden. problems() says in
 * words what can't be used.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Backups\Queries\BackupSetup;
use App\Domain\Backups\Tools\SftpKeyPair;
use App\Domain\Backups\Tools\SshHostKey;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

final class NewPlace
{
    /** A host name or an IPv4 or IPv6 address: nothing that could end a line of ssh_config or start an option. */
    public const string HOST = '/^(?:[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?|[0-9A-Fa-f:]{2,39})$/D';

    public const string USER = '/^[A-Za-z0-9._][A-Za-z0-9._-]{0,63}$/D';

    /** A folder at an S3 bucket or an SFTP server. */
    public const string FOLDER = '/^[A-Za-z0-9._~\/-]{1,500}$/D';

    public const string BUCKET = '/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D';

    public const string REGION = '/^[A-Za-z0-9-]{1,50}$/D';

    /**
     * What can't be used, in words, by field; empty when everything can.
     *
     * @return array<string, string>
     */
    public function problems(): array
    {
        $problems = [];
        $name = trim($this->name);

        if ($name === '' || mb_strlen($name) > 100) {
            $problems['name'] = 'Give the place a name of up to 100 characters.';
        }

        return $problems + match ($this->kind) {
            BackupDestinationKind::Folder => $this->folderProblems(),
            BackupDestinationKind::S3 => $this->s3Problems(),
            BackupDestinationKind::Sftp => $this->sftpProblems(),
        };
    }

    /**
     * @return array<string, string>
     */
    private function folderProblems(): array
    {
        $path = (string) ($this->location['path'] ?? '');
        $normal = self::normalPath($path);

        if (mb_strlen($path) > 500 || preg_match('/[\r\n\0]/', $path) === 1
            || preg_match('#^(/|[A-Za-z]:[\\\\/])#', $path) !== 1 || preg_match('#(^|/)\.\.?(/|$)#', $normal) === 1) {
            return ['path' => 'Give the folder’s full path on this server, such as '.BackupSetup::suggestedFolder().'.'];
        }

        // As written, and through any link: a deploy's symlink can lead into public/.
        $forms = array_values(array_unique([$normal, self::resolved($path)]));

        if (self::insideAny($forms, public_path())) {
            return ['path' => 'Backups can’t be kept inside the site’s public folder, where anyone could download them.'];
        }

        foreach ((array) config('filesystems.disks') as $disk) {
            $root = is_array($disk) && ($disk['driver'] ?? null) === 'local' ? ($disk['root'] ?? null) : null;

            if (is_string($root) && self::insideAny($forms, $root)) {
                return ['path' => "Backups can’t be kept among the site’s own files. Choose a folder outside {$root}."];
            }
        }

        return [];
    }

    /**
     * The words when a new folder place already holds a repository, else
     * null; never asked of a place being changed, whose address must hold
     * its own.
     */
    public function alreadyHoldsBackups(): ?string
    {
        $path = (string) ($this->location['path'] ?? '');

        // restic's own file at a repository's root.
        return $this->kind === BackupDestinationKind::Folder && $path !== '' && is_file($path.DIRECTORY_SEPARATOR.'config')
            ? "There are already backups in {$path}. Choose an empty folder."
            : null;
    }

    /**
     * @return array<string, string>
     */
    private function s3Problems(): array
    {
        $problems = [];
        $endpoint = (string) ($this->location['endpoint'] ?? '');
        $parts = parse_url($endpoint);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || preg_match(self::HOST, $parts['host'] ?? '') !== 1
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ($parts['path'] ?? '') !== '' || preg_match('/[\s\'",]/', $endpoint) === 1) {
            $problems['endpoint'] = 'Give the storage’s address, starting https://, such as https://s3.eu-central-1.amazonaws.com.';
        }

        if (preg_match(self::BUCKET, (string) ($this->location['bucket'] ?? '')) !== 1) {
            $problems['bucket'] = 'A bucket’s name is 3 to 63 lower-case letters, digits, dots and hyphens.';
        }

        if (isset($this->location['path']) && preg_match(self::FOLDER, (string) $this->location['path']) !== 1) {
            $problems['path'] = 'A folder is letters, digits, dots, hyphens, underscores and slashes.';
        }

        if (isset($this->location['region']) && preg_match(self::REGION, (string) $this->location['region']) !== 1) {
            $problems['region'] = 'A region is letters, digits and hyphens, such as eu-central-1.';
        }

        if (trim((string) ($this->credentials['access_key_id'] ?? '')) === '') {
            $problems['access_key_id'] = 'Give the access key ID.';
        }

        if (trim((string) ($this->credentials['secret_access_key'] ?? '')) === '') {
            $problems['secret_access_key'] = 'Give the secret access key.';
        }

        return $problems;
    }

    /**
     * @return array<string, string>
     */
    private function sftpProblems(): array
    {
        $problems = [];
        $port = $this->location['port'] ?? 22;

        if (preg_match(self::HOST, (string) ($this->location['host'] ?? '')) !== 1) {
            $problems['host'] = 'Give the server’s name or IP address, such as backup.example.com.';
        }

        if (! is_int($port) || $port < 1 || $port > 65535) {
            $problems['port'] = 'A port is a number from 1 to 65535.';
        }

        if (preg_match(self::USER, (string) ($this->location['user'] ?? '')) !== 1) {
            $problems['user'] = 'Give the user Teme signs in as: letters, digits, dots, hyphens and underscores.';
        }

        if (preg_match(self::FOLDER, (string) ($this->location['path'] ?? '')) !== 1) {
            $problems['path'] = 'A folder is letters, digits, dots, hyphens, underscores and slashes.';
        }

        try {
            SshHostKey::fromLine((string) ($this->location['host_key'] ?? ''));
        } catch (InvalidArgumentException) {
            $problems['host_key'] = 'Confirm the server’s fingerprint first.';
        }

        return $problems;
    }

    /** Forward slashes, no slash at the end, and lower case where the file system ignores case. */
    private static function normalPath(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    private static function inside(string $path, string $root): bool
    {
        return $root !== '' && ($path === $root || str_starts_with($path, $root.'/'));
    }

    /**
     * Whether any form of the path is inside $root, as written or through its links.
     *
     * @param  list<string>  $forms
     */
    private static function insideAny(array $forms, string $root): bool
    {
        $roots = array_unique([self::normalPath($root), self::normalPath(realpath($root) ?: $root)]);

        foreach ($forms as $form) {
            foreach ($roots as $each) {
                if (self::inside($form, $each)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The path with its deepest existing ancestor resolved through links by
     * realpath(), and the rest, which doesn't exist yet, as written.
     */
    private static function resolved(string $path): string
    {
        $ancestor = rtrim(str_replace('\\', '/', $path), '/');
        $rest = [];

        while (realpath($ancestor) === false) {
            $parent = dirname($ancestor);

            if ($parent === $ancestor || $parent === '.') {
                return self::normalPath($path);
            }

            $rest[] = basename($ancestor);
            $ancestor = $parent;
        }

        return self::normalPath((string) realpath($ancestor).($rest === [] ? '' : '/'.implode('/', array_reverse($rest))));
    }

    /**
     * @param  array<string, string|int>  $location
     * @param  array<string, string>|null  $credentials
     */
    private function __construct(
        public readonly string $id,
        private readonly BackupDestinationKind $kind,
        private readonly string $name,
        private readonly array $location,
        #[SensitiveParameter] private readonly ?array $credentials,
    ) {}

    public static function folder(string $name, string $path): self
    {
        return new self(self::newId(), BackupDestinationKind::Folder, trim($name), ['path' => trim($path)], null);
    }

    public static function s3(
        string $name,
        string $endpoint,
        string $bucket,
        ?string $path,
        ?string $region,
        #[SensitiveParameter] string $accessKeyId,
        #[SensitiveParameter] string $secretAccessKey,
    ): self {
        $location = ['endpoint' => rtrim(trim($endpoint), '/'), 'bucket' => trim($bucket)];

        if (trim((string) $path, ' /') !== '') {
            $location['path'] = trim((string) $path, ' /');
        }

        if (trim((string) $region) !== '') {
            $location['region'] = trim((string) $region);
        }

        return new self(self::newId(), BackupDestinationKind::S3, trim($name), $location, [
            'access_key_id' => trim($accessKeyId),
            'secret_access_key' => $secretAccessKey,
        ]);
    }

    public static function sftp(string $name, string $host, int $port, string $user, string $path, SftpKeyPair $keys, SshHostKey $hostKey): self
    {
        return new self(self::newId(), BackupDestinationKind::Sftp, trim($name), [
            'host' => trim($host),
            'port' => $port,
            'user' => trim($user),
            'path' => trim($path),
            'host_key' => $hostKey->line(),
            'fingerprint' => $hostKey->fingerprint(),
        ], ['private_key' => $keys->privateKey()]);
    }

    /**
     * A place from its parts as they are: for a change, and for a command
     * that names a place on a new server.
     *
     * @param  array<string, string|int>  $location
     * @param  array<string, string>|null  $credentials
     */
    public static function of(BackupDestinationKind $kind, string $name, array $location, #[SensitiveParameter] ?array $credentials, ?string $id = null): self
    {
        if ($kind === BackupDestinationKind::Sftp && isset($location['host_key'])) {
            try {
                $location['fingerprint'] = SshHostKey::fromLine((string) $location['host_key'])->fingerprint();
            } catch (InvalidArgumentException) {
                unset($location['fingerprint']);
            }
        }

        return new self($id ?? self::newId(), $kind, trim($name), $location, $credentials);
    }

    public function kind(): BackupDestinationKind
    {
        return $this->kind;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * What the row's location holds.
     *
     * @return array<string, string|int>
     */
    public function location(): array
    {
        return $this->location;
    }

    /**
     * What the row's credentials hold; null for a folder.
     *
     * @return array<string, string>|null
     */
    public function credentials(): ?array
    {
        return $this->credentials;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'kind' => $this->kind, 'name' => $this->name, 'location' => $this->location, 'credentials' => '[hidden]'];
    }

    /**
     * @return array<never>
     */
    public function __serialize(): array
    {
        throw new LogicException('A new place is never serialized: keep its parts.');
    }

    /**
     * @param  array<mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A new place is never serialized: keep its parts.');
    }

    private static function newId(): string
    {
        return Str::lower((string) Str::ulid());
    }
}

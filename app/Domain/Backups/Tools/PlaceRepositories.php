<?php

declare(strict_types=1);

namespace App\Domain\Backups\Tools;

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\Models\BackupDestination;
use App\Domain\Backups\NewPlace;
use Closure;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use SensitiveParameter;

/**
 * Opens the places backups are kept as restic repositories, for one call
 * (I-2403): a folder by its path; S3 with its keys in restic's environment;
 * an SFTP server through files that live only for the call, in a folder
 * only Teme can read: the place's private key, the host key a person
 * confirmed, and one ssh configuration naming every SFTP place the call
 * opened, so two of them share restic's one sftp.args.
 */
final class PlaceRepositories
{
    /** How long Teme waits on a place while a person waits, in seconds. */
    public const int ANSWER_SECONDS = 60;

    /** How long restic's init may take: it writes about 1,300 files and folders, one round trip each. */
    public const int INIT_SECONDS = 600;

    private ?string $folder = null;

    /** @var array<string, array{host: string, port: int, user: string, hostKey: string}> by place ID */
    private array $sftp = [];

    private function __construct() {}

    /**
     * Runs $work with places it can open, then removes every file it wrote
     * for SSH, whatever the work did.
     *
     * @template T
     *
     * @param  Closure(self): T  $work
     *
     * @return T
     */
    public static function during(Closure $work): mixed
    {
        $places = new self;

        try {
            return $work($places);
        } finally {
            $places->removeFiles();
        }
    }

    /**
     * The place's repository, opened with $key.
     */
    public function repository(BackupDestination|NewPlace $place, #[SensitiveParameter] string $key): ResticRepository
    {
        if ($place instanceof NewPlace) {
            [$id, $kind, $location, $credentials] = [$place->id, $place->kind(), $place->location(), $place->credentials()];

            if ($place->problems() !== []) {
                throw new LogicException('A place is opened only once its problems() are none.');
            }
        } else {
            [$id, $kind, $location, $credentials] = [$place->id, $place->kind, $place->location, $place->credentials];
        }

        if ($kind !== BackupDestinationKind::Folder && $credentials === null) {
            throw new LogicException('A removed place can’t be opened.');
        }

        return match ($kind) {
            BackupDestinationKind::Folder => new ResticRepository((string) $location['path'], $key),
            BackupDestinationKind::S3 => new ResticRepository(self::s3Location($location), $key, self::s3Variables($location, $credentials)),
            BackupDestinationKind::Sftp => $this->sftpRepository($id, $location, $credentials, $key),
        };
    }

    /**
     * Whether restic can open the two in one call: it reads one set of AWS_
     * variables and one of each -o option for both (docs/architecture/backups.md).
     */
    public static function canOpenTogether(ResticRepository $one, ResticRepository $other): bool
    {
        $mine = $one->backendVariables();
        $theirs = $other->backendVariables();

        if ($mine !== [] && $theirs !== [] && $mine != $theirs) {
            return false;
        }

        $options = [];

        foreach ($one->options() as $option) {
            $options[Str::before($option, '=')] = $option;
        }

        foreach ($other->options() as $option) {
            $name = Str::before($option, '=');

            if (array_key_exists($name, $options) && $options[$name] !== $option) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string|int>  $location
     */
    private static function s3Location(array $location): string
    {
        $path = (string) ($location['path'] ?? '');

        return 's3:'.$location['endpoint'].'/'.$location['bucket'].($path === '' ? '' : '/'.$path);
    }

    /**
     * @param  array<string, string|int>  $location
     * @param  array<string, string>  $credentials
     *
     * @return array<string, string>
     */
    private static function s3Variables(array $location, #[SensitiveParameter] array $credentials): array
    {
        $variables = [
            'AWS_ACCESS_KEY_ID' => (string) ($credentials['access_key_id'] ?? ''),
            'AWS_SECRET_ACCESS_KEY' => (string) ($credentials['secret_access_key'] ?? ''),
        ];

        if (($location['region'] ?? '') !== '') {
            $variables['AWS_DEFAULT_REGION'] = (string) $location['region'];
        }

        return $variables;
    }

    /**
     * @param  array<string, string|int>  $location
     * @param  array<string, string>  $credentials
     */
    private function sftpRepository(string $id, array $location, #[SensitiveParameter] array $credentials, #[SensitiveParameter] string $key): ResticRepository
    {
        // Every field reaches ssh_config: one that could end a line there would add an option.
        foreach (['host' => NewPlace::HOST, 'user' => NewPlace::USER, 'path' => NewPlace::FOLDER] as $field => $pattern) {
            if (preg_match($pattern, (string) ($location[$field] ?? '')) !== 1) {
                throw new LogicException("An SFTP place’s {$field} can’t be written to ssh’s configuration.");
            }
        }

        $hostKey = SshHostKey::fromLine((string) ($location['host_key'] ?? ''))->line();
        $folder = $this->folder();

        self::writePrivately("{$folder}/{$id}.key", (string) ($credentials['private_key'] ?? ''));
        $this->sftp[$id] = ['host' => (string) $location['host'], 'port' => (int) ($location['port'] ?? 22), 'user' => (string) $location['user'], 'hostKey' => $hostKey];
        $this->writeConfiguration($folder);

        return new ResticRepository("sftp:teme-{$id}:{$location['path']}", $key, [], ["sftp.args=-F '{$folder}/ssh_config'"]);
    }

    /**
     * The call's own folder under the system's temporary folder, made on
     * first use, readable by Teme's user alone. Forward slashes: restic
     * splits sftp.args as a shell would, so a backslash is an escape.
     */
    private function folder(): string
    {
        if ($this->folder !== null) {
            return $this->folder;
        }

        $folder = str_replace('\\', '/', rtrim(sys_get_temp_dir(), '\\/')).'/teme-ssh-'.Str::lower((string) Str::ulid());

        // restic reads each -o value as CSV, then splits sftp.args on quotes.
        if (preg_match('/[\'",\r\n]/', $folder) === 1) {
            throw new LogicException("A place’s SSH folder can’t hold a quote or a comma: [{$folder}].");
        }

        if (! mkdir($folder, 0700) && ! is_dir($folder)) {
            throw new RuntimeException("Teme couldn’t make a private folder for SSH’s files at {$folder}.");
        }

        chmod($folder, 0700);
        $this->folder = $folder;

        // A fatal error or a timeout skips during()'s finally; this still runs, and does nothing once it has.
        register_shutdown_function(function (): void {
            $this->removeFiles();
        });

        return $folder;
    }

    private function writeConfiguration(string $folder): void
    {
        $knownHosts = '';
        $configuration = '';

        foreach ($this->sftp as $id => $place) {
            $knownHosts .= "teme-{$id} {$place['hostKey']}\n";
            $configuration .= implode("\n", [
                "Host teme-{$id}",
                "    HostName {$place['host']}",
                "    Port {$place['port']}",
                "    User {$place['user']}",
                "    IdentityFile \"{$folder}/{$id}.key\"",
                '    IdentitiesOnly yes',
                "    UserKnownHostsFile \"{$folder}/known_hosts\"",
                "    HostKeyAlias teme-{$id}",
                '    StrictHostKeyChecking yes',
                '    BatchMode yes',
                '    PasswordAuthentication no',
                '    ConnectTimeout 15',
                '    ServerAliveInterval 30',
                '',
            ]);
        }

        self::writePrivately("{$folder}/known_hosts", $knownHosts);
        self::writePrivately("{$folder}/ssh_config", $configuration);
    }

    /**
     * Writes a file only Teme's user can read: ssh refuses a private key
     * anyone else could.
     */
    private static function writePrivately(string $path, #[SensitiveParameter] string $contents): void
    {
        $mask = umask(0077);

        try {
            if (file_put_contents($path, $contents) === false) {
                throw new RuntimeException("Teme couldn’t write {$path}.");
            }

            chmod($path, 0600);
        } finally {
            umask($mask);
        }
    }

    /**
     * Removes every file this call wrote, by its name, then its folder:
     * never by listing it.
     */
    private function removeFiles(): void
    {
        if ($this->folder === null) {
            return;
        }

        foreach ([...array_map(fn (string $id): string => "{$id}.key", array_keys($this->sftp)), 'known_hosts', 'ssh_config'] as $name) {
            if (is_file("{$this->folder}/{$name}")) {
                unlink("{$this->folder}/{$name}");
            }
        }

        if (is_dir($this->folder)) {
            rmdir($this->folder);
        }

        $this->folder = null;
        $this->sftp = [];
    }
}

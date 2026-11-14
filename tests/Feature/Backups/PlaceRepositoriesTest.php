<?php

declare(strict_types=1);

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\NewPlace;
use App\Domain\Backups\Queries\BackupSetup;
use App\Domain\Backups\Tools\PlaceRepositories;
use App\Domain\Backups\Tools\ResticRepository;
use App\Domain\Backups\Tools\SftpKeyPair;
use App\Domain\Backups\Tools\SshHostKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Backups\BackupRecords;
use Tests\Fixtures\Backups\TemporaryRepository;

uses(RefreshDatabase::class);

const PR_HOST_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl';

function prSftp(string $name = 'Storage box', string $host = 'backup.example.com', string $user = 'u123', string $path = '/backups/teme', ?SftpKeyPair $pair = null): NewPlace
{
    return NewPlace::sftp($name, $host, 23, $user, $path, $pair ?? SftpKeyPair::generate(), SshHostKey::fromLine(PR_HOST_KEY));
}

/** The folder of SSH's files that a repository's one option names. */
function prSshFolder(ResticRepository $repository): string
{
    preg_match("#^sftp\.args=-F '(.+)/ssh_config'$#", $repository->options()[0] ?? '', $match);

    return $match[1] ?? '';
}

afterEach(function (): void {
    foreach ($this->folders ?? [] as $folder) {
        TemporaryRepository::remove($folder);
    }
});

it('opens a folder place at its path, with nothing else', function (): void {
    $folder = TemporaryRepository::freshFolder();
    $place = NewPlace::folder('This server', $folder);
    $row = BackupRecords::destination(['location' => ['path' => $folder]]);

    PlaceRepositories::during(function (PlaceRepositories $places) use ($place, $row, $folder): void {
        foreach ([$places->repository($place, 'machine-key'), $places->repository($row, 'machine-key')] as $repository) {
            expect($repository->location)->toBe($folder)
                ->and($repository->backendVariables())->toBe([])
                ->and($repository->options())->toBe([])
                ->and($repository->variables('RESTIC_'))->toBe(['RESTIC_REPOSITORY' => $folder, 'RESTIC_PASSWORD' => 'machine-key']);
        }
    });

    // $ alone would let a line break through at the end; the patterns end with D.
    expect(preg_match(NewPlace::HOST, "backup.example.com\n"))->toBe(0)
        ->and(preg_match(NewPlace::USER, "teme\n"))->toBe(0)
        ->and(preg_match(NewPlace::FOLDER, "/backups\n"))->toBe(0)
        ->and($place->kind())->toBe(BackupDestinationKind::Folder)
        ->and($place->location())->toBe(['path' => $folder])
        ->and($place->credentials())->toBeNull()
        ->and($place->id)->toMatch('/^[0-9a-z]{26}$/')
        ->and($place->problems())->toBe([]);
});

it('puts S3’s keys and region in the environment, never in the location', function (): void {
    $place = NewPlace::s3('Off-site', 'https://s3.example.test/', 'teme-backups', '/site/one/', 'eu-central-1', 'AKIATEST', 'secret-test-value');

    $repository = PlaceRepositories::during(fn (PlaceRepositories $places): ResticRepository => $places->repository($place, 'machine-key'));

    expect($repository->location)->toBe('s3:https://s3.example.test/teme-backups/site/one')
        ->and($repository->backendVariables())->toBe([
            'AWS_ACCESS_KEY_ID' => 'AKIATEST',
            'AWS_SECRET_ACCESS_KEY' => 'secret-test-value',
            'AWS_DEFAULT_REGION' => 'eu-central-1',
        ])
        ->and($repository->options())->toBe([])
        ->and($place->location())->toBe(['endpoint' => 'https://s3.example.test', 'bucket' => 'teme-backups', 'path' => 'site/one', 'region' => 'eu-central-1'])
        ->and($place->credentials())->toBe(['access_key_id' => 'AKIATEST', 'secret_access_key' => 'secret-test-value'])
        ->and($place->problems())->toBe([]);
});

it('writes SFTP’s key, known host and configuration for the call, readable by Teme alone (I-2403)', function (): void {
    $pair = SftpKeyPair::generate();
    $place = prSftp(pair: $pair);
    $seen = [];

    PlaceRepositories::during(function (PlaceRepositories $places) use ($place, $pair, &$seen): void {
        $repository = $places->repository($place, 'machine-key');
        $folder = prSshFolder($repository);
        $seen['folder'] = $folder;

        expect($repository->location)->toBe("sftp:teme-{$place->id}:/backups/teme")
            ->and($repository->backendVariables())->toBe([])
            ->and($folder)->not->toBe('')
            ->and($folder)->not->toContain('\\')
            ->and(file_get_contents("{$folder}/{$place->id}.key"))->toBe($pair->privateKey())
            ->and(file_get_contents("{$folder}/known_hosts"))->toBe("teme-{$place->id} ".PR_HOST_KEY."\n")
            ->and(file_get_contents("{$folder}/ssh_config"))->toBe(implode("\n", [
                "Host teme-{$place->id}",
                '    HostName backup.example.com',
                '    Port 23',
                '    User u123',
                "    IdentityFile \"{$folder}/{$place->id}.key\"",
                '    IdentitiesOnly yes',
                "    UserKnownHostsFile \"{$folder}/known_hosts\"",
                "    HostKeyAlias teme-{$place->id}",
                '    StrictHostKeyChecking yes',
                '    BatchMode yes',
                '    PasswordAuthentication no',
                '    ConnectTimeout 15',
                '    ServerAliveInterval 30',
                '',
            ]));

        if (PHP_OS_FAMILY !== 'Windows') {
            expect(fileperms($folder) & 0777)->toBe(0700)
                ->and(fileperms("{$folder}/{$place->id}.key") & 0777)->toBe(0600);
        }
    });

    expect(is_dir($seen['folder']))->toBeFalse()
        ->and($place->location())->toBe([
            'host' => 'backup.example.com', 'port' => 23, 'user' => 'u123', 'path' => '/backups/teme',
            'host_key' => PR_HOST_KEY, 'fingerprint' => 'SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU',
        ])
        ->and($place->credentials())->toBe(['private_key' => $pair->privateKey()]);
});

it('removes the SSH files after the call, even when the work throws', function (): void {
    $folder = '';

    expect(function () use (&$folder): void {
        PlaceRepositories::during(function (PlaceRepositories $places) use (&$folder): never {
            $folder = prSshFolder($places->repository(prSftp(), 'machine-key'));

            throw new RuntimeException('restic went away');
        });
    })->toThrow(RuntimeException::class, 'restic went away');

    expect($folder)->not->toBe('')
        ->and(is_dir($folder))->toBeFalse();
});

it('opens two SFTP places in one call with one shared option', function (): void {
    $first = prSftp('Storage box');
    $second = prSftp('Second box', 'backup2.example.com', 'teme', '/srv/teme');

    PlaceRepositories::during(function (PlaceRepositories $places) use ($first, $second): void {
        $one = $places->repository($first, 'machine-key');
        $other = $places->repository($second, 'machine-key');
        $folder = prSshFolder($one);

        expect($other->options())->toBe($one->options())
            ->and(PlaceRepositories::canOpenTogether($one, $other))->toBeTrue()
            ->and(file_get_contents("{$folder}/ssh_config"))->toContain("Host teme-{$first->id}\n")
            ->and(file_get_contents("{$folder}/ssh_config"))->toContain("Host teme-{$second->id}\n")
            ->and(file_get_contents("{$folder}/known_hosts"))->toBe("teme-{$first->id} ".PR_HOST_KEY."\nteme-{$second->id} ".PR_HOST_KEY."\n");
    });
});

it('tells which places restic can open together', function (): void {
    $s3 = fn (string $secret, ?string $region = null): NewPlace => NewPlace::s3('S3 '.$secret, 'https://s3.example.test', 'teme-backups', null, $region, 'AKIATEST', $secret);

    PlaceRepositories::during(function (PlaceRepositories $places) use ($s3): void {
        $open = fn (NewPlace $place): ResticRepository => $places->repository($place, 'machine-key');
        $folder = $open(NewPlace::folder('This server', TemporaryRepository::freshFolder()));

        expect(PlaceRepositories::canOpenTogether($folder, $open($s3('one'))))->toBeTrue()
            ->and(PlaceRepositories::canOpenTogether($open($s3('one')), $open($s3('one'))))->toBeTrue()
            ->and(PlaceRepositories::canOpenTogether($open($s3('one')), $open($s3('two'))))->toBeFalse()
            ->and(PlaceRepositories::canOpenTogether($open($s3('one', 'eu-central-1')), $open($s3('one'))))->toBeFalse()
            ->and(PlaceRepositories::canOpenTogether($open(prSftp()), $open($s3('one'))))->toBeTrue();
    });
});

it('refuses a host, user or folder that would add a line to ssh’s configuration', function (string $field, string $value): void {
    $place = match ($field) {
        'host' => prSftp(host: $value),
        'user' => prSftp(user: $value),
        'path' => prSftp(path: $value),
    };

    expect($place->problems())->toBe([$field => match ($field) {
        'host' => 'Give the server’s name or IP address, such as backup.example.com.',
        'user' => 'Give the user Teme signs in as: letters, digits, dots, hyphens and underscores.',
        'path' => 'A folder is letters, digits, dots, hyphens, underscores and slashes.',
    }]);

    PlaceRepositories::during(fn (PlaceRepositories $places) => expect(fn () => $places->repository($place, 'machine-key'))->toThrow(LogicException::class));
})->with([
    'a host and a second line' => ['host', "backup.example.com\n    ProxyCommand touch /tmp/owned"],
    'a host with a hash' => ['host', 'backup#example.com'],
    'a host with a space' => ['host', 'backup example.com'],
    'a host that is an option' => ['host', '-oProxyCommand=touch'],
    'a host with a token' => ['host', 'backup%h.example.com'],
    'a host with a quote' => ['host', 'backup"example.com'],
    'a user and a second line' => ['user', "teme\n    ProxyCommand touch /tmp/owned"],
    'a user that is an option' => ['user', '-lroot'],
    'a folder and a second line' => ['path', "/backups\nHost *"],
    'a folder with a quote' => ['path', "/backups'teme"],
    'a folder with a space' => ['path', '/back ups'],
]);

it('refuses a folder inside the public folder or among the site’s own files', function (): void {
    $existing = TemporaryRepository::make();
    $this->folders = [$existing->folder];

    expect(NewPlace::folder('Here', public_path('backups'))->problems())
        ->toBe(['path' => 'Backups can’t be kept inside the site’s public folder, where anyone could download them.'])
        ->and(NewPlace::folder('Here', storage_path('app/public/media/backups'))->problems()['path'] ?? '')
        ->toStartWith('Backups can’t be kept among the site’s own files. Choose a folder outside ')
        ->and(NewPlace::folder('Here', storage_path('app/private/backups'))->problems())->toHaveKey('path')
        ->and(NewPlace::folder('Here', 'backups')->problems())
        ->toBe(['path' => 'Give the folder’s full path on this server, such as '.BackupSetup::suggestedFolder().'.'])
        ->and(NewPlace::folder('Here', BackupSetup::suggestedFolder().'/../public')->problems())->toHaveKey('path')
        ->and(NewPlace::folder('Here', $existing->folder)->problems())->toBe([])
        ->and(NewPlace::folder('Here', $existing->folder)->alreadyHoldsBackups())
        ->toBe("There are already backups in {$existing->folder}. Choose an empty folder.")
        ->and(NewPlace::folder('Here', TemporaryRepository::freshFolder())->problems())->toBe([])
        ->and(NewPlace::folder('Here', TemporaryRepository::freshFolder())->alreadyHoldsBackups())->toBeNull();
});

it('refuses a folder reached through a link into the public folder', function (): void {
    $link = TemporaryRepository::freshFolder();

    if (! @symlink(public_path(), $link)) {
        $this->markTestSkipped('This system can’t make a symbolic link here.');
    }

    try {
        expect(NewPlace::folder('Here', $link.DIRECTORY_SEPARATOR.'backups')->problems())
            ->toBe(['path' => 'Backups can’t be kept inside the site’s public folder, where anyone could download them.']);
    } finally {
        is_link($link) && (PHP_OS_FAMILY === 'Windows' ? rmdir($link) : unlink($link));
    }
});

it('refuses S3 settings it can’t use', function (): void {
    $s3 = fn (array $change): array => NewPlace::s3(...[
        'name' => 'Off-site', 'endpoint' => 'https://s3.example.test', 'bucket' => 'teme-backups', 'path' => null,
        'region' => null, 'accessKeyId' => 'AKIATEST', 'secretAccessKey' => 'secret-test-value', ...$change,
    ])->problems();

    expect($s3(['endpoint' => 'http://s3.example.test']))->toBe(['endpoint' => 'Give the storage’s address, starting https://, such as https://s3.eu-central-1.amazonaws.com.'])
        ->and($s3(['endpoint' => 'https://s3.example.test/teme-backups']))->toHaveKey('endpoint')
        ->and($s3(['endpoint' => 'https://user:pass@s3.example.test']))->toHaveKey('endpoint')
        ->and($s3(['bucket' => 'Teme_Backups']))->toBe(['bucket' => 'A bucket’s name is 3 to 63 lower-case letters, digits, dots and hyphens.'])
        ->and($s3(['path' => 'site one']))->toBe(['path' => 'A folder is letters, digits, dots, hyphens, underscores and slashes.'])
        ->and($s3(['region' => 'eu central']))->toBe(['region' => 'A region is letters, digits and hyphens, such as eu-central-1.'])
        ->and($s3(['accessKeyId' => ' ', 'secretAccessKey' => '']))->toBe(['access_key_id' => 'Give the access key ID.', 'secret_access_key' => 'Give the secret access key.'])
        ->and($s3(['name' => '']))->toBe(['name' => 'Give the place a name of up to 100 characters.'])
        ->and($s3(['name' => str_repeat('a', 101)]))->toHaveKey('name')
        ->and($s3([]))->toBe([]);
});

it('never serializes a new place, nor shows its credentials when dumped', function (): void {
    $place = NewPlace::s3('Off-site', 'https://s3.example.test', 'teme-backups', null, null, 'AKIATEST', 'secret-test-value');

    expect(fn () => serialize($place))->toThrow(LogicException::class, 'A new place is never serialized: keep its parts.')
        ->and(print_r($place, true))->not->toContain('secret-test-value')
        ->and(json_encode($place))->not->toContain('secret-test-value');
});

<?php

/**
 * A place as the setup and place forms send it, made into 1.12c's NewPlace;
 * NewPlace says in words what can't be used (I-2401).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Tools\Backups;

use App\Domain\Backups\BackupDestinationKind;
use App\Domain\Backups\NewPlace;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LogicException;

final class PlaceForm
{
    /**
     * The kind of place the form chose.
     *
     * @throws ValidationException
     */
    public static function kind(Request $request): BackupDestinationKind
    {
        return BackupDestinationKind::tryFrom((string) $request->input('kind'))
            ?? throw ValidationException::withMessages(['kind' => __('Choose where backups are kept.')]);
    }

    /**
     * A folder or S3 place from the form; an SFTP place is SftpSteps' two steps.
     */
    public static function place(BackupDestinationKind $kind, Request $request): NewPlace
    {
        return match ($kind) {
            BackupDestinationKind::Folder => NewPlace::folder(trim((string) $request->input('name')), trim((string) $request->input('path'))),
            BackupDestinationKind::S3 => NewPlace::s3(
                trim((string) $request->input('name')),
                trim((string) $request->input('endpoint')),
                trim((string) $request->input('bucket')),
                trim((string) $request->input('folder')) ?: null,
                trim((string) $request->input('region')) ?: null,
                (string) $request->input('access_key_id'),
                (string) $request->input('secret_access_key'),
            ),
            BackupDestinationKind::Sftp => throw new LogicException('An SFTP place is made in two steps, by SftpSteps.'),
        };
    }

    /**
     * An SFTP place's first-step fields.
     *
     * @return array{name: string, host: string, port: int, user: string, path: string}
     */
    public static function sftpFields(Request $request): array
    {
        return [
            'name' => trim((string) $request->input('name')),
            'host' => trim((string) $request->input('host')),
            'port' => $request->integer('port', 22),
            'user' => trim((string) $request->input('user')),
            'path' => trim((string) $request->input('folder')),
        ];
    }

    /**
     * The location keys a changed place of $kind sends, as ChangeBackupDestination reads them.
     *
     * @return array<string, string|int>
     */
    public static function changedLocation(Request $request, string $kind): array
    {
        return match ($kind) {
            BackupDestinationKind::Folder->value => ['path' => trim((string) $request->input('path'))],
            BackupDestinationKind::S3->value => array_filter([
                'endpoint' => rtrim(trim((string) $request->input('endpoint')), '/'),
                'bucket' => trim((string) $request->input('bucket')),
                'path' => trim(trim((string) $request->input('folder')), ' /'),
                'region' => trim((string) $request->input('region')),
            ], fn (string $value, string $key): bool => $value !== '' || in_array($key, ['endpoint', 'bucket'], true), ARRAY_FILTER_USE_BOTH),
            default => [
                'host' => trim((string) $request->input('host')),
                'port' => $request->integer('port', 22),
                'user' => trim((string) $request->input('user')),
                'path' => trim((string) $request->input('folder')),
            ],
        };
    }

    /**
     * The credentials a changed S3 place sends: both keys, or null to keep
     * the ones Teme has. Folder and SFTP places change none (an SFTP place keeps its pair).
     *
     * @return array{access_key_id: string, secret_access_key: string}|null
     *
     * @throws ValidationException
     */
    public static function changedCredentials(Request $request, string $kind): ?array
    {
        if ($kind !== BackupDestinationKind::S3->value) {
            return null;
        }

        $id = (string) $request->input('access_key_id');
        $secret = (string) $request->input('secret_access_key');

        if ($id === '' && $secret === '') {
            return null;
        }

        if ($id === '' || $secret === '') {
            throw ValidationException::withMessages(['secret_access_key' => __('Give both keys to change them, or leave both empty to keep them.')]);
        }

        return ['access_key_id' => $id, 'secret_access_key' => $secret];
    }
}

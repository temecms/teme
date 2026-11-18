<?php

/**
 * Records one ask of GitHub (I-?? draft): a list found replaces
 * teme_releases whole, an unchanged answer keeps it, a failure keeps it and
 * says why; update_checks' one row says when and with what result. The
 * operator only: the scheduler or teme:updates:check.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Updates\Operations;

use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use App\Domain\Updates\Operations\Input\RecordReleaseCheckInput;
use App\Domain\Updates\Release;
use App\Domain\Updates\ReleaseCheck;
use App\Domain\Updates\TemeVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class RecordReleaseCheck extends BaseOperation
{
    /** The advisory lock that keeps two checks from interleaving. */
    public const string LOCK = 'teme.updates.check';

    protected function authorize(OperationInput $input): void
    {
        if ($input->actorId() !== null || ! Operator::isPresent()) {
            throw new AuthorizationException('Only the operator records an update check.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RecordReleaseCheckInput $input */
        $seen = [];

        foreach ($input->check->releases as $release) {
            if (! TemeVersion::isRelease($release->version)) {
                throw ValidationException::withMessages(['releases' => ["{$release->version} isn’t a release’s version."]]);
            }

            if (isset($seen[$release->version])) {
                throw ValidationException::withMessages(['releases' => ["The release list names {$release->version} twice."]]);
            }

            $seen[$release->version] = true;
        }
    }

    protected function perform(OperationInput $input): string
    {
        /** @var RecordReleaseCheckInput $input */
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [self::LOCK]);
        $check = $input->check;
        $at = $input->at;
        $row = ['id' => 1, 'checked_at' => $at, 'succeeded_at' => $at, 'error' => null, 'etag' => $check->etag, 'token_set' => $input->tokenSet];

        if ($check->outcome === ReleaseCheck::FOUND) {
            $versions = array_map(fn (Release $release): string => $release->version, $check->releases);
            // An empty list removes every release: GitHub lists none.
            DB::table('teme_releases')->whereNotIn('version', $versions)->delete();
            DB::table('teme_releases')->upsert(array_map(fn (Release $release): array => [
                'version' => $release->version,
                'prerelease' => $release->prerelease,
                'security' => $release->security,
                'maintenance' => $release->maintenance,
                'notes' => $release->notes,
                'url' => $release->url,
                'published_at' => $release->publishedAt,
                'seen_at' => $at,
            ], $check->releases), ['version'], ['prerelease', 'security', 'maintenance', 'notes', 'url', 'published_at', 'seen_at']);
            DB::table('update_checks')->upsert([$row], ['id'], ['checked_at', 'succeeded_at', 'error', 'etag', 'token_set']);
        } elseif ($check->outcome === ReleaseCheck::UNCHANGED) {
            DB::table('update_checks')->upsert([$row], ['id'], ['checked_at', 'succeeded_at', 'error', 'token_set']);
        } else {
            DB::table('update_checks')->upsert([[...$row, 'succeeded_at' => null, 'error' => $check->error, 'etag' => null]], ['id'], ['checked_at', 'error', 'token_set']);
        }

        return $check->outcome;
    }
}

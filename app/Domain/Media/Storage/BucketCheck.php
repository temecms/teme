<?php

/**
 * Proves a bucket setup before any media moves there (I-3456): on each
 * bucket it writes a test file, reads its size back, and checks that the
 * public bucket serves it at its public address while the originals bucket
 * serves it only through a signed address, then deletes it. It changes no
 * database state, and never shows a key or a secret.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Storage;

use App\Domain\Media\MediaLocations;
use Aws\Exception\AwsException;
use Closure;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class BucketCheck
{
    /** Where the test files are written, inside each bucket's root. */
    public const string PROBE_FOLDER = '_teme-check';

    /** What a provider's error code means, in words. */
    private const array CODES = [
        'SignatureDoesNotMatch' => 'the secret key is wrong',
        'InvalidAccessKeyId' => 'the access key ID is wrong',
        'NoSuchBucket' => 'the bucket doesn’t exist at this endpoint',
        'AccessDenied' => 'the access key isn’t allowed to do this in the bucket',
        'AccessControlListNotSupported' => 'this bucket doesn’t accept ACLs: leave MEDIA_BUCKET_ACL empty',
        'RequestTimeTooSkewed' => 'this server’s clock is off',
        'PermanentRedirect' => 'the bucket is in another region: set MEDIA_BUCKET_REGION to it',
        'AuthorizationHeaderMalformed' => 'the region is wrong: set MEDIA_BUCKET_REGION to the bucket’s',
    ];

    /** @var list<array{state: 'ok'|'warning'|'failed', line: string}> */
    private array $lines = [];

    /**
     * Each refusal of the configuration, in words, found before any request
     * is made; [] when the roles may be checked.
     *
     * @param  list<'public'|'originals'>  $roles
     *
     * @return list<string>
     */
    public static function refusals(array $roles): array
    {
        $disks = MediaLocations::disks('bucket');
        $refusals = [];

        foreach ($roles as $role) {
            $config = (array) config("filesystems.disks.{$disks[$role]}");

            if (($config['bucket'] ?? null) === null || $config['bucket'] === '') {
                $refusals[] = $role === 'public'
                    ? __('MEDIA_BUCKET isn’t set: name the public bucket.')
                    : __('MEDIA_ORIGINALS_BUCKET isn’t set: name the private bucket for image originals.');
            }

            foreach (['endpoint' => 'MEDIA_BUCKET_ENDPOINT', 'url' => 'MEDIA_BUCKET_URL'] as $key => $variable) {
                $address = (string) ($config[$key] ?? '');

                if ($address !== '' && str_starts_with(strtolower($address), 'http://') && ! self::local($address)) {
                    $refusals[] = __(':variable is an http:// address on the internet: use https://, or http:// only for a server on this network.', ['variable' => $variable]);
                }
            }
        }

        if (in_array('public', $roles, true) && (string) (config("filesystems.disks.{$disks['public']}.url") ?? '') === '') {
            $refusals[] = __('MEDIA_BUCKET_URL isn’t set: give the CDN or public address in front of the public bucket.');
        }

        if (self::sameBucket()) {
            $refusals[] = __('The public bucket and the originals bucket are the same bucket. Use two: a public bucket would serve the originals too.');
        }

        return array_values(array_unique($refusals));
    }

    /**
     * Whether both bucket disks name one bucket on one endpoint.
     */
    public static function sameBucket(): bool
    {
        $disks = MediaLocations::disks('bucket');
        $public = (array) config("filesystems.disks.{$disks['public']}");
        $originals = (array) config("filesystems.disks.{$disks['originals']}");
        $bucket = (string) ($public['bucket'] ?? '');

        return $bucket !== ''
            && $bucket === (string) ($originals['bucket'] ?? '')
            && rtrim(strtolower((string) ($public['endpoint'] ?? '')), '/') === rtrim(strtolower((string) ($originals['endpoint'] ?? '')), '/');
    }

    /**
     * Runs the check on each role's bucket. Returns every step's line, and
     * whether none failed.
     *
     * @param  list<'public'|'originals'>  $roles
     *
     * @return array{passed: bool, lines: list<array{state: 'ok'|'warning'|'failed', line: string}>}
     */
    public function run(array $roles): array
    {
        $this->lines = [];
        $disks = MediaLocations::disks('bucket');

        foreach ($roles as $role) {
            $this->check($role, $disks[$role]);
        }

        return [
            'passed' => array_filter($this->lines, fn (array $line): bool => $line['state'] === 'failed') === [],
            'lines' => $this->lines,
        ];
    }

    /**
     * @param  'public'|'originals'  $role
     */
    private function check(string $role, string $name): void
    {
        $label = $role === 'public' ? __('Public bucket') : __('Originals bucket');
        $disk = Storage::disk($name);

        if (! $disk instanceof AwsS3V3Adapter) {
            $this->say('failed', __(':label: the disk “:disk” isn’t an S3 bucket.', ['label' => $label, 'disk' => $name]));

            return;
        }

        $probe = self::PROBE_FOLDER.'/'.Str::lower((string) Str::ulid()).'.txt';
        $bytes = 'Teme checked this bucket at '.now()->toIso8601String().'.';
        $written = false;

        try {
            if (! $this->step($label, __('writing a test file'), fn (): bool => $disk->put($probe, $bytes))) {
                return;
            }

            $written = true;

            if (! $this->step($label, __('reading its size back'), fn (): bool => $disk->size($probe) === strlen($bytes))) {
                return;
            }

            $role === 'public' ? $this->servedPublicly($label, $disk, $probe, $bytes) : $this->servedPrivately($label, $disk, $probe, $bytes);
        } finally {
            if ($written) {
                $this->step($label, __('deleting the test file'), fn (): bool => $disk->delete($probe));
            }
        }
    }

    private function servedPublicly(string $label, AwsS3V3Adapter $disk, string $probe, string $bytes): void
    {
        $address = $disk->url($probe);
        $answer = $this->get($address);

        if ($answer === null || $answer['status'] !== 200 || $answer['body'] !== $bytes) {
            $this->say('failed', __(':label: the public address :address didn’t serve the test file (:answer). Make the bucket public, or point the CDN at it.', [
                'label' => $label,
                'address' => $address,
                'answer' => $answer === null ? __('no answer') : __('it answered :status', ['status' => $answer['status']]),
            ]));

            return;
        }

        $this->say('ok', __(':label: the public address serves the test file.', ['label' => $label]));

        if (! str_contains(strtolower($answer['cacheControl']), 'immutable')) {
            $this->say('warning', __(':label: the public address doesn’t send Cache-Control with immutable. Some CDNs rewrite it; browsers will ask for images again sooner.', ['label' => $label]));
        }
    }

    private function servedPrivately(string $label, AwsS3V3Adapter $disk, string $probe, string $bytes): void
    {
        $direct = $disk->getClient()->getObjectUrl((string) $disk->getConfig()['bucket'], $disk->path($probe));
        $unsigned = $this->get($direct);

        if ($unsigned !== null && $unsigned['status'] === 200) {
            $this->say('failed', __(':label: the originals bucket is public: anyone can read an original at its address. Make the bucket private.', ['label' => $label]));

            return;
        }

        $this->say('ok', __(':label: refuses the test file without a signature.', ['label' => $label]));
        $signed = $this->get($disk->temporaryUrl($probe, now()->addMinute()));

        if ($signed === null || $signed['status'] !== 200 || $signed['body'] !== $bytes) {
            $this->say('failed', __(':label: a signed address didn’t serve the test file (:answer), so editors couldn’t download originals.', [
                'label' => $label,
                'answer' => $signed === null ? __('no answer') : __('it answered :status', ['status' => $signed['status']]),
            ]));

            return;
        }

        $this->say('ok', __(':label: serves the test file through a signed address.', ['label' => $label]));
    }

    /**
     * One step against the bucket, said as done or failed with the
     * provider's error in words.
     *
     * @param  Closure(): bool  $work
     */
    private function step(string $label, string $doing, Closure $work): bool
    {
        try {
            $done = $work();
        } catch (Throwable $failure) {
            $this->say('failed', __(':label: :doing failed: :reason.', ['label' => $label, 'doing' => $doing, 'reason' => self::reason($failure)]));

            return false;
        }

        $this->say($done ? 'ok' : 'failed', $done
            ? __(':label: :doing worked.', ['label' => $label, 'doing' => $doing])
            : __(':label: :doing failed.', ['label' => $label, 'doing' => $doing]));

        return $done;
    }

    /**
     * The provider's error code in words, and the code; never its message,
     * which can hold a request's details.
     */
    public static function reason(Throwable $failure): string
    {
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof AwsException) {
                $code = (string) $cause->getAwsErrorCode();

                if ($code === '') {
                    return __('the provider couldn’t be reached');
                }

                return isset(self::CODES[$code]) ? __(self::CODES[$code]).' ('.$code.')' : __('the provider answered :code', ['code' => $code]);
            }
        }

        return __('the provider couldn’t be reached');
    }

    /**
     * @return array{status: int, body: string, cacheControl: string}|null
     */
    private function get(string $address): ?array
    {
        try {
            $response = Http::connectTimeout(5)->timeout(15)->withoutRedirecting()->get($address);
        } catch (Throwable) {
            return null;
        }

        return ['status' => $response->status(), 'body' => $response->body(), 'cacheControl' => (string) $response->header('Cache-Control')];
    }

    /**
     * @param  'ok'|'warning'|'failed'  $state
     */
    private function say(string $state, string $line): void
    {
        $this->lines[] = ['state' => $state, 'line' => $line];
    }

    /**
     * Whether an http:// address names a server on this machine or network:
     * a loopback or private address, localhost, or a name without a dot, as
     * a container's service is called.
     */
    private static function local(string $address): bool
    {
        $host = strtolower(trim((string) parse_url($address, PHP_URL_HOST), '[]'));

        if ($host === '' || $host === 'localhost') {
            return $host === 'localhost';
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        return ! str_contains($host, '.');
    }
}

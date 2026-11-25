<?php

/**
 * The active image service (docs/guides/developers/plugins/image-service.md):
 * the driver of the first enabled plugin, oldest enabled first, that was
 * granted media.read and declares one. Asked for each size's address as
 * media is drawn; core's address stands where it gives none, fails, or
 * gives one that isn't an `https://` address on its declared host or a
 * path on this site. Scoped to a request or a job, which finds the driver
 * once and records its fault once.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Images;

use App\Domain\Cache\RenderRecord;
use App\Domain\Media\MediaDisks;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Seo\SeoPlugin;
use App\Domain\Plugins\Seo\SeoPlugins;
use App\Domain\Plugins\Seo\SocketAnswerRefused;
use App\Domain\Site\Policy\PagePolicy;
use Teme\Contracts\Media\HasImageService;
use Teme\Contracts\Media\ImageService;
use Teme\Contracts\Plugins\Capability;
use Throwable;

final class ImageServices
{
    /** The socket's name in a recorded fault. */
    public const string SOCKET = 'image-service';

    /** @var array{plugin: SeoPlugin, host: string, driver: ImageService}|false|null null until looked for; false for none */
    private array|false|null $active = null;

    private bool $faulted = false;

    public function __construct(
        private readonly SeoPlugins $plugins,
        private readonly LoadedPlugins $loaded,
    ) {}

    /**
     * The address of this size of this media item, as MediaDisks' public
     * disk writes it when no driver answers: the driver's when it gives a
     * usable one, else core's for the stored path.
     */
    public function address(string $mediaId, string $variant, string $path): string
    {
        $active = $this->active();

        if ($active === null) {
            return MediaDisks::public()->url($path);
        }

        // Its being enabled, and its settings, change every image's address.
        app(RenderRecord::class)->tag('plugin:'.$active['plugin']->code);

        try {
            $given = $active['driver']->imageAddress($mediaId, $variant, strtolower(pathinfo($path, PATHINFO_EXTENSION)));
        } catch (Throwable $failure) {
            $this->fault($active['plugin'], $failure);

            return MediaDisks::public()->url($path);
        }

        if ($given === null) {
            return MediaDisks::public()->url($path);
        }

        if (! self::usable($given, $active['host'])) {
            $this->fault($active['plugin'], new SocketAnswerRefused('An image address must be an https:// address on '.$active['host'].', or a path on this site starting with a single /.'));

            return MediaDisks::public()->url($path);
        }

        if (str_starts_with($given, 'https://')) {
            app(PagePolicy::class)->allowOrigins('img-src', [self::originOf($active['host'])]);
        }

        return $given;
    }

    /**
     * The active service's origin for a content security policy's img-src,
     * or null with none.
     */
    public function origin(): ?string
    {
        $active = $this->active();

        return $active === null ? null : self::originOf($active['host']);
    }

    /**
     * Whether a driver's answer may be drawn: an https address with no
     * user, password or port, on the declared host, or a path on this site.
     */
    public static function usable(string $address, string $host): bool
    {
        if (preg_match('/[\s\x00-\x1f\x7f"\'<>`\\\\]/', $address) === 1 || strlen($address) > 2_000) {
            return false;
        }

        if (preg_match('~^/(?!/)~', $address) === 1) {
            return true;
        }

        $parts = parse_url($address);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['port']) || ! isset($parts['host'])) {
            return false;
        }

        $given = strtolower($parts['host']);

        return str_starts_with($host, '*.') ? str_ends_with($given, substr($host, 1)) : $given === $host;
    }

    private static function originOf(string $host): string
    {
        return 'https://'.$host;
    }

    /**
     * @return array{plugin: SeoPlugin, host: string, driver: ImageService}|null
     */
    private function active(): ?array
    {
        if ($this->active === null) {
            $this->active = $this->find() ?? false;
        }

        return $this->active === false ? null : $this->active;
    }

    /**
     * The first enabled plugin's driver; the admin's choice among several is a later screen.
     *
     * @return array{plugin: SeoPlugin, host: string, driver: ImageService}|null
     */
    private function find(): ?array
    {
        // No plugin running here hands one over: no need to read the records.
        if (array_filter($this->loaded->running(), fn (object $plugin): bool => $plugin instanceof HasImageService) === []) {
            return null;
        }

        foreach ($this->plugins->all() as $plugin) {
            $declared = ImageServiceDeclaration::of($plugin->manifest);

            if ($declared === null || ! $plugin->grants(Capability::MediaRead->value) || ! $plugin->running instanceof HasImageService) {
                continue;
            }

            try {
                return ['plugin' => $plugin, 'host' => $declared['host'], 'driver' => $plugin->running->imageService()];
            } catch (Throwable $failure) {
                $this->fault($plugin, $failure);
            }
        }

        return null;
    }

    private function fault(SeoPlugin $plugin, Throwable $failure): void
    {
        if ($this->faulted) {
            return;
        }

        $this->faulted = true;
        $this->plugins->fault($plugin, self::SOCKET, ImageService::class, $failure);
    }
}

<?php

/**
 * The site's web app manifest JSON: name and the 192 and 512 icons from the
 * design site_icon when its favicon variants already exist (issue 388).
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Queries;

use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Media\MediaUrls;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Queries\SiteIconUse;
use App\Domain\Site\Head\SiteIcons;
use Teme\Contracts\Settings\SettingsReader;

final readonly class SiteWebManifest
{
    public function __construct(
        private SettingsReader $settings,
    ) {}

    /**
     * The manifest body as an array for JSON encoding.
     *
     * @return array{name: string, short_name: string, icons: list<array{src: string, sizes: string, type: string}>}
     */
    public function data(): array
    {
        $name = $this->settings->string('general', 'site_title');
        $name = $name === '' ? __('Site') : $name;
        $icons = [];
        $chosen = $this->settings->string(SiteIconUse::GROUP, SiteIconUse::KEY);

        if ($chosen !== '') {
            $media = Media::query()->whereKey($chosen)->where('kind', 'image')->whereNull('deleted_at')->first();

            if ($media !== null && ! str_contains(strtolower($media->mime_type), 'svg')) {
                app(RenderRecord::class)->tag(PageTags::media($chosen));
                $variants = (array) $media->variants;

                foreach ([192, 512] as $size) {
                    $url = MediaUrls::variant($variants, 'icon-'.$size, $media->id);

                    if ($url !== null) {
                        $icons[] = [
                            'src' => $this->absolute($url),
                            'sizes' => SiteIcons::sizeOf($variants, 'icon-'.$size),
                            'type' => 'image/png',
                        ];
                    }
                }
            }
        }

        return [
            'name' => $name,
            'short_name' => $name,
            'icons' => $icons,
        ];
    }

    private function absolute(string $address): string
    {
        if (preg_match('~^https?://~i', $address) === 1) {
            return $address;
        }

        return rtrim(url('/'), '/').'/'.ltrim($address, '/');
    }
}

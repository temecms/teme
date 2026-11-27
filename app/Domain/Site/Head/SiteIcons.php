<?php

/**
 * Core's site icon links in the public head (issue 388): the design group's
 * site_icon media setting when set and its favicon variants exist, else the
 * active theme's site_icon file in its public folder. Variants are made on
 * the queue, never during a page view. Plugins may not write these relations
 * (HeadCleaner).
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Head;

use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Media\MediaUrls;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Queries\SiteIconUse;
use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\Document\HeadContributor;
use App\Domain\Site\Document\LinkRelation;
use App\Domain\Site\Document\LinkTag;
use App\Domain\Site\Document\Precedence;
use App\Domain\Themes\ActiveTheme;
use Teme\Contracts\Settings\SettingsReader;

final class SiteIcons implements HeadContributor
{
    public function __construct(
        private readonly SettingsReader $settings,
        private readonly ActiveTheme $active,
    ) {}

    public function precedence(): Precedence
    {
        return Precedence::Core;
    }

    public function entries(DocumentPage $page): array
    {
        $chosen = $this->settings->string(SiteIconUse::GROUP, SiteIconUse::KEY);

        if ($chosen !== '') {
            $fromMedia = $this->fromMedia($chosen);

            if ($fromMedia !== []) {
                return $fromMedia;
            }
        }

        return $this->fromTheme();
    }

    /**
     * @return list<LinkTag>
     */
    private function fromMedia(string $id): array
    {
        $media = Media::query()->whereKey($id)->where('kind', 'image')->whereNull('deleted_at')->first();

        if ($media === null || str_contains(strtolower($media->mime_type), 'svg')) {
            return [];
        }

        app(RenderRecord::class)->tag(PageTags::media($id));

        $variants = (array) $media->variants;
        $links = [];
        $icon32 = MediaUrls::variant($variants, 'icon-32', $media->id);
        $apple = MediaUrls::variant($variants, 'icon-180', $media->id);

        if ($icon32 !== null) {
            $links[] = new LinkTag(LinkRelation::Icon, $this->absolute($icon32), type: 'image/webp', sizes: '32x32');
        }

        if ($apple !== null) {
            $links[] = new LinkTag(LinkRelation::AppleTouchIcon, $this->absolute($apple), type: 'image/webp');
        }

        if ($icon32 !== null || MediaUrls::variant($variants, 'icon-192', $media->id) !== null) {
            $links[] = new LinkTag(LinkRelation::Manifest, $this->absolute(route('site-webmanifest')));
        }

        return $links;
    }

    /**
     * @return list<LinkTag>
     */
    private function fromTheme(): array
    {
        $theme = $this->active->get();
        $file = $theme?->manifest?->siteIcon() ?? '';

        if ($theme === null || $file === '') {
            return [];
        }

        $folder = realpath($theme->path.'/public');
        $path = realpath($theme->path.'/public/'.$file);

        if ($folder === false || $path === false || ! is_file($path) || ! str_starts_with($path, $folder.DIRECTORY_SEPARATOR)) {
            return [];
        }

        $url = $this->absolute(asset('themes/'.$theme->code.'/'.$file));
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if ($extension === 'svg') {
            return [new LinkTag(LinkRelation::Icon, $url, type: 'image/svg+xml')];
        }

        return [new LinkTag(LinkRelation::Icon, $url)];
    }

    /** An address that is already absolute stays; a site-relative one is rooted. */
    private function absolute(string $address): string
    {
        if (preg_match('~^https?://~i', $address) === 1) {
            return $address;
        }

        return rtrim(url('/'), '/').'/'.ltrim($address, '/');
    }
}

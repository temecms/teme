<?php

/**
 * The site's own listing addresses as a theme reads them from `$site`: the
 * posts page, and each public type's archive page, in the page's language
 * and generated from the current site address, so none is stored or typed
 * into a template.
 *
 * @package Teme
 * @since   1.7.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\ListingTypes;
use App\Domain\Addresses\Paths;
use App\Domain\Addresses\TypeArchives;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Languages\PageLanguage;
use App\Domain\Languages\SiteLanguages;
use Illuminate\Support\Facades\DB;

final class SiteAddresses
{
    /**
     * What each page language's addresses came to, as this request has read them.
     *
     * @var array<string, array{postsPage: string, archives: array<string, string>}>
     */
    private array $known = [];

    public function __construct(private readonly PageLanguage $page, private readonly SiteLanguages $languages) {}

    /**
     * The address of the page listing the latest posts, absolute: the posts
     * page when Settings › Reading names one that readers can see, else the
     * home page while the home page shows the latest posts, both in the
     * page's language; empty while a static front page is shown and no posts
     * page is live in it, when no page lists the latest posts.
     */
    public function posts(): string
    {
        return $this->read()['postsPage'];
    }

    /**
     * Each public type's archive page, absolute, by type key, in the page's
     * language. The first public type with an archive lists the latest posts,
     * so its address is the posts page's, and it has none when that is empty.
     *
     * @return array<string, string>
     */
    public function archives(): array
    {
        return $this->read()['archives'];
    }

    /**
     * @return array{postsPage: string, archives: array<string, string>}
     */
    private function read(): array
    {
        $stored = $this->page->stored();

        return $this->known[$stored ?? ''] ??= $this->build($stored);
    }

    /**
     * @return array{postsPage: string, archives: array<string, string>}
     */
    private function build(?string $stored): array
    {
        $root = rtrim(url('/'), '/');
        $path = $this->postsPath($stored);
        // With a static front page and no posts page that is live in this language, no page lists the latest posts.
        $posts = $path !== null ? $root.$path : (AddressSettings::current()->frontShows === AddressSettings::FRONT_PAGE ? '' : $root.$this->home($stored));
        $archives = [];

        foreach (ListingTypes::withArchive() as $index => $type) {
            $path = TypeArchives::path($type);

            if ($index === 0) {
                if ($posts !== '') {
                    $archives[$type] = $posts;
                }
            } elseif ($path !== null) {
                $archives[$type] = $root.Addresses::toUrl(ListingViews::prefixed($stored, $path));
            }
        }

        return ['postsPage' => $posts, 'archives' => $archives];
    }

    /** The home page's address in a language, as a site-relative path. */
    private function home(?string $stored): string
    {
        $prefix = $this->languages->prefix($stored ?? $this->languages->defaultTag());

        return $prefix === '' ? '/' : '/'.$prefix.'/';
    }

    /**
     * The posts page's address in this language, as a site-relative path: its
     * own while it is live, or its translation's; null when Reading names
     * none or none is live, the home page then being where the posts are.
     */
    private function postsPath(?string $stored): ?string
    {
        $settings = AddressSettings::current();

        if ($settings->frontShows !== AddressSettings::FRONT_PAGE || $settings->postsPageId === '') {
            return null;
        }

        $item = DB::table('contents')->where('id', $settings->postsPageId)->first(['id', 'translation_group']);

        if ($item === null) {
            return null;
        }

        $versions = DB::table('contents')
            ->where(fn ($same) => $same->where('id', $item->id)->when($item->translation_group !== null, fn ($query) => $query->orWhere('translation_group', $item->translation_group)))
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->where('visibility', '<>', 'private')
            ->when($stored === null, fn ($query) => $query->whereNull('language'), fn ($query) => $query->where('language', $stored));
        $version = $this->languages->whereShown($versions)->value('id');

        if ($version === null) {
            return null;
        }

        app(RenderRecord::class)->tag(PageTags::content((string) $version));
        $path = Paths::current('content', (string) $version);

        return $path === null ? null : Addresses::toUrl($path);
    }
}

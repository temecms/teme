<?php

declare(strict_types=1);

namespace App\Domain\Seo\Queries;

use App\Domain\Addresses\AddressSettings;
use App\Domain\Cards\CardMedia;
use App\Domain\Contents\Queries\EditorSession;
use App\Domain\Contents\Queries\LinkedContent;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Seo\SocialCards;
use App\Domain\Seo\TitlesAndListing;
use App\Domain\Seo\WholeSite;
use App\Domain\Site\Breadcrumbs;
use App\Domain\Site\SiteDates;
use App\Domain\Site\SiteLanguage;
use App\Domain\StructuredData\TypeSchemas;
use App\Domain\Types\ContentTypeRegistry;
use Teme\Contracts\Settings\SettingsReader;

/**
 * What the editor's Search and social section reads beside the item's own
 * fields (I-290): the title row its head uses and the tokens the
 * screen can't know, whether the site or its type keeps it out of search,
 * the host an outside canonical is compared with, the site's default social
 * image, what the type says its items are, whether its address follows its
 * primary category, whether the theme draws a trail, and the canonical's
 * chosen item. Every rule is PageHead's.
 */
final readonly class EditorSearchAndSocial
{
    public function __construct(
        private SettingsReader $settings,
        private ContentTypeRegistry $types,
        private SiteLanguage $language,
        private CardMedia $media,
        private TypeSchemas $schemas,
        private Breadcrumbs $trail,
    ) {}

    /**
     * The section's data for the item the session opens.
     *
     * @return array{kind: string, template: string, separator: string, tokens: array<string, string>, discouraged: bool, typeHidden: bool, siteHost: string, defaultImage: array{id: string, media: array<string, mixed>}|null, canonicalItem: array{id: string, title: string|null, state: string}|null, schema: array{pageType: string, mainEntity: string|null, mainEntities: list<string>}, addressFollowsCategory: bool, trail: bool}
     */
    public function for(EditorSession $session): array
    {
        $site = $this->settings->string('general', 'site_title');
        $tagline = trim($this->settings->string('general', 'tagline'));
        $addresses = AddressSettings::current();
        $front = $addresses->frontShows === AddressSettings::FRONT_PAGE;

        $kind = match (true) {
            $front && $addresses->frontPageId === $session->id => 'home',
            $front && $addresses->postsPageId === $session->id => 'latest',
            default => 'item',
        };

        return [
            'kind' => $kind,
            'template' => TitlesAndListing::template($kind === 'home' ? 'home' : "type:{$session->type}"),
            'separator' => TitlesAndListing::separator(),
            'tokens' => match ($kind) {
                'home' => ['title' => $site, 'site' => $site, 'tagline' => $tagline],
                'latest' => ['site' => $site, 'tagline' => $tagline],
                default => ['site' => $site, 'tagline' => $tagline, ...$this->itemTokens($session)],
            },
            'discouraged' => WholeSite::discouraged(),
            'typeHidden' => TitlesAndListing::hidesType($session->type),
            'siteHost' => strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
            'defaultImage' => $this->defaultImage(),
            'canonicalItem' => $this->canonicalItem($session->fields),
            'schema' => $this->schema($session->type),
            // A {category} in the pattern moves a published item when its primary category changes (I-869).
            'addressFollowsCategory' => $addresses->followsCategory($session->type),
            'trail' => $this->trail->wanted(),
        ];
    }

    /**
     * What the type tells search engines its items are, and the article
     * types an item may choose instead (I-709).
     *
     * @return array{pageType: string, mainEntity: string|null, mainEntities: list<string>}
     */
    private function schema(string $type): array
    {
        $schema = $this->schemas->for($type);

        return ['pageType' => $schema->pageType, 'mainEntity' => $schema->mainEntity, 'mainEntities' => $schema->mainEntities];
    }

    /**
     * An item's tokens the page fills from its record: its type's singular,
     * its first author, and the date and year it shows, which for an item not
     * yet published are today's, the day it would go live.
     *
     * @return array{type: string, author: string, date: string, year: string}
     */
    private function itemTokens(EditorSession $session): array
    {
        $published = SiteDates::inSite($session->publishedAt ?? now());

        return [
            'type' => $this->types->has($session->type) ? $this->types->get($session->type)->singular : '',
            'author' => $session->author['name'] ?? '',
            'date' => SiteDates::show($published, $this->language->forContent($session->id)),
            'year' => $published->format('Y'),
        ];
    }

    /**
     * The site's default social image as cards draw it, or null when it is
     * unset or no longer a live image (I-293).
     *
     * @return array{id: string, media: array<string, mixed>}|null
     */
    private function defaultImage(): ?array
    {
        $id = SocialCards::defaultImage();
        $found = $id === null ? [] : $this->media->forIds([$id]);

        return $id === null || ! isset($found[$id]) ? null : ['id' => $id, 'media' => $found[$id]];
    }

    /**
     * The item the canonical names, with its title and state, or null for
     * the item's own address or an outside one (I-292).
     *
     * @param  array<string, mixed>  $fields
     *
     * @return array{id: string, title: string|null, state: string}|null
     */
    private function canonicalItem(array $fields): ?array
    {
        $choice = $fields[BuiltInFields::CANONICAL] ?? null;
        $id = is_array($choice) && is_string($choice['item'] ?? null) && $choice['item'] !== '' ? $choice['item'] : null;

        if ($id === null) {
            return null;
        }

        $found = LinkedContent::states([$id])[$id] ?? ['title' => null, 'state' => 'missing'];

        return ['id' => $id, 'title' => $found['title'], 'state' => $found['state']];
    }
}

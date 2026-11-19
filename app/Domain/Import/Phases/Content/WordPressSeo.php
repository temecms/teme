<?php

/**
 * The active SEO plugin's values for one item, as the companion sends them
 * (I-330), in Teme's built-in fields: titles and descriptions as plain words
 * cut to Teme's lengths; the canonical by ID, or left for the second pass;
 * the social image by ID; the three snippet directives and Yoast's page and
 * article types. Never the old site's address.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Content;

use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\Rules\CanonicalChoice;
use App\Domain\Import\Convert\ConversionReport;
use App\Domain\Import\Convert\ConversionSite;
use App\Domain\Import\Convert\Links\SiteLinks;
use App\Domain\Import\Convert\Media\ImageTrace;
use App\Domain\Import\Convert\Media\MediaTargets;
use App\Domain\Import\Convert\SourceTargets;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\Terms\WordPressText;
use App\Domain\StructuredData\TypeSchemas;

final readonly class WordPressSeo
{
    /** Each text field: its key, its length, the companion's key and its name in a message. */
    private const array TEXTS = [
        [BuiltInFields::SEO_TITLE, 200, 'title', 'SEO title'],
        [BuiltInFields::META_DESCRIPTION, 320, 'description', 'meta description'],
        [BuiltInFields::SOCIAL_TITLE, 200, 'social_title', 'social title'],
        [BuiltInFields::SOCIAL_DESCRIPTION, 320, 'social_description', 'social description'],
    ];

    /** Yoast's page types Teme's closed list has (BuiltInFields::PAGE_TYPE). */
    private const array PAGE_TYPES = ['WebPage', 'AboutPage', 'ContactPage', 'CollectionPage'];

    /** Yoast's article types Teme's closed list has, each with Teme's value. */
    private const array ARTICLE_TYPES = [['Article', 'Article'], ['NewsArticle', 'NewsArticle'], ['BlogPosting', 'BlogPosting'], ['None', TypeSchemas::NONE]];

    public function __construct(private ConversionSite $site, private SourceTargets $targets, private MediaTargets $media) {}

    /**
     * Returns the fields to set from the plugin's $seo values, for an item
     * whose type has the fields $supported names; the term the plugin chose
     * as primary in $primaryTaxonomy (WordPress's term_id); a canonical left
     * for the second pass; whether the social image was the old site's but
     * isn't here; the fields cut short, by name; and what to say.
     *
     * @param  list<string>  $supported
     *
     * @return array{fields: array<string, mixed>, primaryTermId: int|null, pendingCanonical: string|null, missingImage: bool, shortened: list<string>, messages: list<ImportMessage>}
     */
    public function read(mixed $seo, int $wordpressId, ?string $ownPath, array $supported, ?string $primaryTaxonomy): array
    {
        if (! is_array($seo) || ! in_array(BuiltInFields::SEO_TITLE, $supported, true)) {
            return ['fields' => [], 'primaryTermId' => null, 'pendingCanonical' => null, 'missingImage' => false, 'shortened' => [], 'messages' => []];
        }

        $fields = [];
        $shortened = [];
        $variables = [];
        $messages = [];
        $had = array_values(array_filter((array) ($seo['had_variables'] ?? []), is_string(...)));

        foreach (self::TEXTS as [$key, $length, $from, $name]) {
            $text = trim((string) preg_replace('/\s+/u', ' ', WordPressText::decoded(is_string($seo[$from] ?? null) ? $seo[$from] : '')));

            if ($text === '' || ! in_array($key, $supported, true)) {
                continue;
            }

            if (mb_strlen($text) > $length) {
                $text = rtrim(mb_substr($text, 0, $length));
                $shortened[] = $name;
            }

            $fields[$key] = $text;

            if (in_array($from, $had, true)) {
                $variables[] = $name;
            }
        }

        if (($seo['noindex'] ?? null) === true && in_array(BuiltInFields::NOINDEX, $supported, true)) {
            $fields[BuiltInFields::NOINDEX] = true;
        }

        if (($seo['nofollow'] ?? null) === true) {
            $messages[] = new ImportMessage('seo.nofollow-not-kept', 'It asked search engines not to follow its links. Teme doesn’t keep that for one item, so they follow them as they do elsewhere.');
        }

        [$canonical, $pending, $dropped] = $this->canonical($seo['canonical'] ?? null, $wordpressId, $ownPath);

        if ($canonical !== null && in_array(BuiltInFields::CANONICAL, $supported, true)) {
            $fields[BuiltInFields::CANONICAL] = $canonical;
        }

        if ($dropped !== null) {
            $messages[] = new ImportMessage('seo.canonical-dropped', "Its canonical address, {$dropped}, isn’t one Teme can keep, so search engines are sent to the item itself.");
        }

        [$image, $missing, $host] = $this->image($seo['social_image_id'] ?? null, $seo['social_image_url'] ?? null);

        if ($image !== null && in_array(BuiltInFields::SOCIAL_IMAGE, $supported, true)) {
            $fields[BuiltInFields::SOCIAL_IMAGE] = $image;
        }

        if ($missing) {
            $messages[] = new ImportMessage('seo.social-image-missing', 'Its social image isn’t in Teme’s library, so its featured image stands in for now. A later import sets it if the image comes across.');
        }

        if ($host !== null) {
            $messages[] = new ImportMessage('seo.social-image-outside', "Its social image is on another site ({$host}), and Teme shows images only from its library, so its featured image stands in.", ['host' => $host]);
        }

        foreach ([[BuiltInFields::NOSNIPPET, 'nosnippet'], [BuiltInFields::NOARCHIVE, 'noarchive']] as [$key, $from]) {
            if (($seo[$from] ?? null) === true && in_array($key, $supported, true)) {
                $fields[$key] = true;
            }
        }

        $longest = $seo['max_snippet'] ?? null;

        if (is_int($longest) && $longest >= 0 && in_array(BuiltInFields::MAX_SNIPPET, $supported, true)) {
            $fields[BuiltInFields::MAX_SNIPPET] = $longest;
        }

        [$fields, $messages] = $this->types($seo, $supported, $fields, $messages);

        if ($variables !== []) {
            $listed = WordPressPost::listed($variables);
            $messages[] = new ImportMessage('seo.variables', "Its {$listed} used its SEO plugin’s variables; Teme kept the words they gave on WordPress, which won’t follow later changes to the title.", ['fields' => $variables]);
        }

        $primary = $primaryTaxonomy === null ? null : ((array) ($seo['primary_terms'] ?? []))[$primaryTaxonomy] ?? null;

        return [
            'fields' => $fields,
            'primaryTermId' => is_int($primary) && $primary > 0 ? $primary : null,
            'pendingCanonical' => $pending,
            'missingImage' => $missing,
            'shortened' => $shortened,
            'messages' => $messages,
        ];
    }

    /**
     * The canonical field's value: null for the item itself or none, the
     * item an old-site address leads to, another site's address; with an
     * old-site path left for the second pass, or an address it couldn't
     * keep (cut to 100 characters).
     *
     * @return array{0: array{item: string}|array{address: string}|null, 1: string|null, 2: string|null}
     */
    private function canonical(mixed $value, int $wordpressId, ?string $ownPath): array
    {
        $address = is_string($value) ? trim($value) : '';

        if ($address === '') {
            return [null, null, null];
        }

        $page = $this->site->uploadsKey($address) === null ? $this->site->pageOf($address) : null;

        if ($page === null) {
            $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
            $keeps = ! $this->site->isOwn($address) && strlen($address) <= 2048
                && preg_match(CanonicalChoice::ADDRESS, $address) === 1 && filter_var($address, FILTER_VALIDATE_URL) !== false
                && strtolower((string) parse_url($address, PHP_URL_HOST)) !== $appHost;

            return $keeps ? [['address' => $address], null, null] : [null, null, mb_substr($address, 0, 100)];
        }

        parse_str($page['query'], $vars);
        $number = $vars['p'] ?? $vars['page_id'] ?? null;
        $itself = ($ownPath !== null && $page['query'] === '' && ConversionSite::pageKey($page['path']) === ConversionSite::pageKey('/'.$ownPath))
            || (is_string($number) && ctype_digit($number) && (int) $number === $wordpressId);

        if ($itself) {
            return [null, null, null];
        }

        $id = (new SiteLinks($this->site, $this->media, $this->targets, new ConversionReport))->content($address);

        return $id !== null
            ? [['item' => $id], null, null]
            : [null, $page['path'].($page['query'] === '' ? '' : '?'.$page['query']), null];
    }

    /**
     * The social image's value: the attachment by its ID, else the file at
     * its address below the old uploads folder; whether it was the old
     * site's but isn't here; another site's host.
     *
     * @return array{0: array{id: string, alt: null, decorative: false}|null, 1: bool, 2: string|null}
     */
    private function image(mixed $id, mixed $url): array
    {
        $number = is_int($id) ? $id : (is_string($id) && ctype_digit($id) ? (int) $id : 0);
        $found = $number > 0 ? $this->media->attachment($number) : null;
        $address = is_string($url) ? trim($url) : '';
        $key = $found === null && $address !== '' ? $this->site->uploadsKey($address) : null;
        // Any size or edited copy of it, as the converter traces an image.
        $found ??= $key === null ? null : ImageTrace::lookup($key, $this->media);

        if ($found !== null) {
            return [['id' => $found, 'alt' => null, 'decorative' => false], false, null];
        }

        if ($key !== null || ($address === '' && $number > 0)) {
            return [null, true, null];
        }

        // The old site's own address outside its uploads (a page, a script): nothing Teme can show, and not another site's.
        if ($address === '' || $this->site->isOwn($address)) {
            return [null, false, null];
        }

        return [null, false, $this->site->hostOf($address)];
    }

    /**
     * Yoast's page and article types where Teme's closed lists have them,
     * a message for each one they haven't.
     *
     * @param  array<mixed>  $seo
     * @param  list<string>  $supported
     * @param  array<string, mixed>  $fields
     * @param  list<ImportMessage>  $messages
     *
     * @return array{0: array<string, mixed>, 1: list<ImportMessage>}
     */
    private function types(array $seo, array $supported, array $fields, array $messages): array
    {
        $page = is_string($seo['page_type'] ?? null) ? trim($seo['page_type']) : '';
        $article = is_string($seo['article_type'] ?? null) ? trim($seo['article_type']) : '';

        if ($page !== '' && in_array(BuiltInFields::PAGE_TYPE, $supported, true)) {
            if (in_array($page, self::PAGE_TYPES, true)) {
                $fields[BuiltInFields::PAGE_TYPE] = $page;
            } else {
                $messages[] = self::typeNotKept($page);
            }
        }

        if ($article !== '' && in_array(BuiltInFields::ARTICLE_TYPE, $supported, true)) {
            $kept = null;

            foreach (self::ARTICLE_TYPES as [$from, $to]) {
                if ($from === $article) {
                    $kept = $to;
                }
            }

            if ($kept !== null) {
                $fields[BuiltInFields::ARTICLE_TYPE] = $kept;
            } else {
                $messages[] = self::typeNotKept($article);
            }
        }

        return [$fields, $messages];
    }

    private static function typeNotKept(string $value): ImportMessage
    {
        $value = mb_substr($value, 0, 60);

        return new ImportMessage('seo.type-not-kept', "Its SEO plugin told search engines it was a {$value}, which isn’t one of Teme’s types, so it uses the default for its type.", ['value' => $value]);
    }
}

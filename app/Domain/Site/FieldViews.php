<?php

declare(strict_types=1);

namespace App\Domain\Site;

use App\Domain\Addresses\Addresses;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Cards\CardMedia;
use App\Domain\Fields\Field;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Types\DatetimeField;
use App\Domain\Fields\Types\EntryField;
use App\Domain\Fields\Types\MediaField;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Site\ViewModels\FieldView;
use Illuminate\Support\Facades\DB;

/**
 * Items' fields as templates draw them (I-3346): every field of the nine
 * types an item's type has, built before the template runs, for one article
 * or for a page of cards at once. Every item the values link to is read in
 * one statement and every image in one call, never one a field or a card.
 */
final readonly class FieldViews
{
    /**
     * What a template meets for each field type (I-3346): the PHP type of
     * `value`, the words of `text`, and when the field is empty. The theme
     * reference prints it, and fieldView() follows it.
     *
     * @var array<string, array{value: string, text: string, empty: string}>
     */
    public const array TYPES = [
        'text' => ['value' => 'string', 'text' => 'the text', 'empty' => "`''`"],
        'textarea' => ['value' => 'string', 'text' => 'the same (a template turns lines into `<br>` after escaping)', 'empty' => "`''`"],
        'number' => ['value' => 'int|float|null', 'text' => 'as stored: `(string) $value`, such as `166` or `3.5`', 'empty' => 'null'],
        'select' => ['value' => 'string|null', 'text' => 'its label; a choice no longer offered, the stored value itself', 'empty' => "null or `''`"],
        'boolean' => ['value' => 'bool', 'text' => "`Yes` when true, `''` when false", 'empty' => 'false'],
        'url' => ['value' => 'string', 'text' => 'the address', 'empty' => "`''`"],
        'date' => ['value' => 'DateTimeImmutable|null', 'text' => "`SiteDates::show(…, 'LL')` in the item's language", 'empty' => 'null'],
        'datetime' => ['value' => 'DateTimeImmutable|null', 'text' => "`SiteDates::show(…, 'LLL')`", 'empty' => 'null'],
        'media' => ['value' => 'string|null', 'text' => "`''`", 'empty' => 'null, or the item is missing or in the trash'],
        'entry' => ['value' => 'string|null', 'text' => 'its published title', 'empty' => 'null, or the item has no address (not published, trashed, private or gone)'],
    ];

    /** The field types a template is given, by the name each answers. */
    private const array DRAWN = ['text', 'textarea', 'number', 'select', 'boolean', 'url', 'datetime', 'media', 'entry'];

    public function __construct(
        private CardMedia $media,
        private SiteLanguage $language,
    ) {}

    /**
     * One article's fields as its template draws them, by handle, in its
     * type's order.
     *
     * @param  array<string, mixed>  $stored  the revision's fields, by key
     *
     * @return array<string, FieldView>
     */
    public function forArticle(string $type, string $contentId, array $stored): array
    {
        return $this->forCards([['id' => $contentId, 'type' => $type, 'fields' => $stored]])[$contentId] ?? [];
    }

    /**
     * Each item's fields as a template draws them: item ID, then handle, in
     * its type's order.
     *
     * @param  list<array{id: string, type: string, fields: array<string, mixed>, language?: string|null}>  $items  each item's ID, type, published fields by key and, where read, its stored language
     *
     * @return array<string, array<string, FieldView>>
     */
    public function forCards(array $items): array
    {
        $values = $this->values($items);

        if ($values === []) {
            return [];
        }

        $itemIds = [];

        foreach ($values as $fields) {
            foreach ($fields as [$field, $value]) {
                if ($field->type instanceof EntryField && is_string($value) && $value !== '') {
                    $itemIds[] = $value;
                }
            }
        }

        $linked = $this->linkedItems(array_values(array_unique($itemIds)));
        $media = $this->media->forIds($this->mediaIdsOf($values));
        $views = [];

        // The language each item's row stores, where the caller read it, so
        // a list of cards asks nothing more (I-3360).
        $languages = [];

        foreach ($items as $item) {
            if (array_key_exists('language', $item)) {
                $languages[$item['id']] = $this->language->of($item['language']);
            }
        }

        foreach ($values as $id => $fields) {
            $language = $languages[(string) $id] ?? $this->language->forContent((string) $id);

            foreach ($fields as [$field, $value]) {
                $views[(string) $id][$field->handle] = $this->fieldView($field, $value, $language, $linked, $media);
            }
        }

        return $views;
    }

    /**
     * The media IDs these items' field values name, so a listing reads them
     * with its featured images in its one call.
     *
     * @param  list<array{id: string, type: string, fields: array<string, mixed>, language?: string|null}>  $items
     *
     * @return list<string>
     */
    public function mediaIds(array $items): array
    {
        return $this->mediaIdsOf($this->values($items));
    }

    /**
     * Each item's drawn fields, each with its stored value, or its type's
     * default when nothing is stored.
     *
     * @param  list<array{id: string, type: string, fields: array<string, mixed>, language?: string|null}>  $items
     *
     * @return array<string, list<array{0: Field, 1: mixed}>>
     */
    private function values(array $items): array
    {
        $fieldsOf = [];
        $values = [];

        foreach ($items as $item) {
            $fieldsOf[$item['type']] ??= array_values(array_filter(
                app(FieldRegistry::class)->forType($item['type']),
                fn (Field $field): bool => in_array($field->type->name(), self::DRAWN, true),
            ));

            foreach ($fieldsOf[$item['type']] as $field) {
                $value = array_key_exists($field->key, $item['fields']) ? $item['fields'][$field->key] : $field->type->default($field);
                $values[$item['id']][] = [$field, $value];
            }
        }

        return $values;
    }

    /**
     * @param  array<string, list<array{0: Field, 1: mixed}>>  $values
     *
     * @return list<string>
     */
    private function mediaIdsOf(array $values): array
    {
        $ids = [];

        foreach ($values as $fields) {
            foreach ($fields as [$field, $value]) {
                if ($field->type instanceof MediaField && ($shown = $field->type->render($field, $value)) !== null) {
                    $ids[] = $shown->id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * One field as its template draws it, by what its field type renders:
     * the plain value, its words, and what to link or draw (the field-value
     * table in docs/reference/theme.md).
     *
     * @param  array<string, array{title: string, url: string}>  $items  the published items linked to, by ID
     * @param  array<string, mixed>  $media  the live media items, by ID
     */
    private function fieldView(Field $field, mixed $value, string $language, array $items, array $media): FieldView
    {
        $name = $field->type->name();
        $rendered = $name === 'entry' ? null : $field->type->render($field, $value);
        $plain = $rendered;
        $text = '';
        $url = null;
        $image = null;
        $empty = false;

        switch ($name) {
            case 'number':
                $text = $rendered === null ? '' : (string) $rendered;
                $empty = $rendered === null;
                break;
            case 'select':
                $plain = $rendered['value'] ?? null;
                $text = $rendered['label'] ?? '';
                $empty = $plain === null || $plain === '';
                break;
            case 'boolean':
                $text = $rendered === true ? 'Yes' : '';
                $empty = $rendered !== true;
                break;
            case 'url':
                $reachable = is_string($rendered) && preg_match('#^https?://#i', $rendered) === 1;
                $plain = $reachable ? $rendered : '';
                $text = $plain;
                $url = $reachable ? $rendered : null;
                $empty = ! $reachable;
                break;
            case 'datetime':
                $name = $field->type instanceof DatetimeField && $field->type->dateOnly ? 'date' : 'datetime';
                $text = $rendered === null ? '' : SiteDates::show($rendered, $language, $name === 'date' ? 'LL' : 'LLL');
                $empty = $rendered === null;
                break;
            case 'media':
                $image = $rendered !== null && isset($media[$rendered->id]) ? $rendered : null;
                $plain = $image?->id;
                $empty = $image === null;
                break;
            case 'entry':
                $plain = is_string($value) && $value !== '' ? $value : null;
                $item = $plain === null ? null : ($items[$plain] ?? null);
                $text = $item['title'] ?? '';
                $url = $item === null || $item['url'] === '' ? null : $item['url'];
                $plain = $url === null ? null : $plain;
                $text = $url === null ? '' : $text;
                $empty = $url === null;
                break;
            default:
                $plain = is_string($rendered) ? $rendered : '';
                $text = $plain;
                $empty = $plain === '';
        }

        return new FieldView($field->handle, $field->label, $name, $plain, $text, $url, $image, $field->show->value, $empty);
    }

    /**
     * The published, live, public items with these IDs: each one's title
     * and its address now, in one statement. Each ID asked is tagged, so
     * the page changes when the item does.
     *
     * @param  list<string>  $ids
     *
     * @return array<string, array{title: string, url: string}>
     */
    private function linkedItems(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        app(RenderRecord::class)->tag(...array_map(PageTags::content(...), $ids));

        $items = [];

        foreach (DB::table('contents')
            ->join('content_revisions', 'content_revisions.id', '=', 'contents.published_revision_id')
            ->leftJoin('paths', fn ($join) => $join->on('paths.target_id', '=', DB::raw('contents.id::text'))->where('paths.target_type', 'content')->where('paths.is_current', true))
            ->whereIn('contents.id', $ids)
            ->where('contents.status', 'published')
            ->whereNull('contents.deleted_at')
            ->where('contents.visibility', '<>', 'private')
            ->get(['contents.id', 'contents.type', 'content_revisions.title', 'paths.path']) as $row) {
            // An item in a hidden language is drawn as its title alone (D7).
            $items[(string) $row->id] = ['title' => (string) $row->title, 'url' => $row->path === null || app(SiteLanguages::class)->isHiddenPath((string) $row->path) ? '' : Addresses::toUrl((string) $row->path, (string) $row->type)];
        }

        return $items;
    }
}

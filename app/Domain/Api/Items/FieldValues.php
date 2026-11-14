<?php

declare(strict_types=1);

namespace App\Domain\Api\Items;

use App\Domain\Api\FieldList;
use App\Domain\Api\Live;
use App\Domain\Api\Shapes\Dates;
use App\Domain\Api\Shapes\ItemLinkShape;
use App\Domain\Api\Shapes\MediaShape;
use App\Domain\Api\Ulids;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Cards\CardMedia;
use App\Domain\Fields\Field;
use App\Domain\Site\SiteLanguage;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/**
 * An item's fields as the API gives them (D6, Q3 A): only those its type
 * shows on the site, keyed by handle in the type's order, each read from
 * its stored value, never through a field type's render(). The items an
 * entry field names are read in one statement, and its files in one.
 */
final readonly class FieldValues
{
    /**
     * Each field type's value, by the name `/types` gives it.
     *
     * @var array<string, string>
     */
    public const array TYPES = [
        'text' => 'A string, or null when empty.',
        'textarea' => 'A string, or null when empty.',
        'number' => 'An integer or a number, or null.',
        'select' => 'The chosen option as {"value", "label"}, or null.',
        'boolean' => 'true or false, never null.',
        'url' => 'An http or https address, or null.',
        'date' => 'A date as YYYY-MM-DD, or null.',
        'datetime' => 'A moment in RFC 3339 in UTC, or null.',
        'media' => 'A media file with this field’s alt text, or null when unset, in the trash or missing.',
        'entry' => 'The item it names as {"id", "type", "language", "title", "path", "url"} while it is live and public, or null.',
    ];

    public function __construct(private CardMedia $media, private SiteLanguage $language) {}

    /**
     * The shown fields' values, keyed by handle in the type's order; `{}`
     * when the type shows none.
     *
     * @param  array<string, mixed>  $stored  the published revision's fields, by key
     */
    public function for(string $type, array $stored): object
    {
        $fields = FieldList::shown($type);
        $values = [];

        foreach ($fields as $field) {
            $values[$field->key] = $stored[$field->key] ?? $field->type->default($field);
        }

        $entries = $this->entries($fields, $values);
        $files = $this->filesNamed($fields, $values);
        $shown = new stdClass;

        foreach ($fields as $field) {
            $shown->{$field->handle} = $this->value($field, $values[$field->key], $entries, $files);
        }

        return $shown;
    }

    /**
     * One field's value from its stored one.
     *
     * @param  array<string, array<string, mixed>>  $entries
     * @param  array<string, array<string, mixed>>  $files
     */
    private function value(Field $field, mixed $value, array $entries, array $files): mixed
    {
        return match (FieldList::typeName($field)) {
            'text', 'textarea' => is_string($value) && trim($value) !== '' ? $value : null,
            'number' => is_int($value) || is_float($value) ? $value : (is_numeric($value) ? $value + 0 : null),
            'select' => is_scalar($value) && (string) $value !== '' ? ['value' => (string) $value, 'label' => $field->options[(string) $value] ?? (string) $value] : null,
            'boolean' => $value === true,
            'url' => is_string($value) && preg_match('#^https?://#i', trim($value)) === 1 ? trim($value) : null,
            'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null,
            'datetime' => self::moment($value),
            'media' => $this->file($value, $files),
            'entry' => is_string($value) ? ($entries[$value] ?? null) : null,
            // A field type the API doesn't describe yet gives nothing rather than its stored form.
            default => null,
        };
    }

    /**
     * A stored date and time in UTC, or null.
     */
    private static function moment(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Dates::utc($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A media field's file with this use's alt text and decorative, or null.
     *
     * @param  array<string, array<string, mixed>>  $files
     *
     * @return array<string, mixed>|null
     */
    private function file(mixed $value, array $files): ?array
    {
        $id = is_array($value) && is_string($value['id'] ?? null) ? $value['id'] : '';

        if (! isset($files[$id])) {
            return null;
        }

        $alt = is_string($value['alt'] ?? null) && trim($value['alt']) !== '' ? $value['alt'] : null;

        return MediaShape::of(['id' => $id, ...$files[$id]], $alt, (bool) ($value['decorative'] ?? false));
    }

    /**
     * The items the entry fields name while they are live and public, by ID,
     * as linked items; one statement. Each ID is noted on the page, shown
     * or not, so its publication shows it (D18).
     *
     * @param  list<Field>  $fields
     * @param  array<string, mixed>  $values
     *
     * @return array<string, array<string, mixed>>
     */
    private function entries(array $fields, array $values): array
    {
        $ids = [];

        foreach ($fields as $field) {
            $value = $values[$field->key];

            if (FieldList::typeName($field) === 'entry' && is_string($value) && preg_match(Ulids::LOWER, $value) === 1) {
                $ids[] = $value;
            }
        }

        if ($ids === []) {
            return [];
        }

        $ids = array_values(array_unique($ids));
        app(RenderRecord::class)->tag(...array_map(PageTags::content(...), $ids));
        $entries = [];

        foreach (Live::items()
            ->join('content_revisions as revision', 'revision.id', '=', 'contents.published_revision_id')
            ->join('paths', fn (JoinClause $join) => $join->on('paths.target_id', '=', DB::raw('contents.id::text'))->where('paths.target_type', 'content')->where('paths.is_current', true))
            ->whereIn('contents.id', $ids)
            ->get(['contents.id', 'contents.type', 'contents.language', 'revision.title', 'paths.path']) as $row) {
            $entries[(string) $row->id] = ItemLinkShape::of((string) $row->id, (string) $row->type, $this->language->of($row->language), (string) $row->title, (string) $row->path);
        }

        return $entries;
    }

    /**
     * The files the media fields name, in one statement; one in the trash
     * or missing is left out.
     *
     * @param  list<Field>  $fields
     * @param  array<string, mixed>  $values
     *
     * @return array<string, array<string, mixed>>
     */
    private function filesNamed(array $fields, array $values): array
    {
        $ids = [];

        foreach ($fields as $field) {
            $value = $values[$field->key];

            if (FieldList::typeName($field) === 'media' && is_array($value) && is_string($value['id'] ?? null) && $value['id'] !== '') {
                $ids[] = $value['id'];
            }
        }

        return $this->media->forIds($ids);
    }
}

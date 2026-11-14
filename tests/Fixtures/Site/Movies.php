<?php

declare(strict_types=1);

namespace Tests\Fixtures\Site;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Tests\Fixtures\Fields\StoredFieldRows;

/**
 * The stored type movie with a field of every one of the nine types, and
 * movies holding values in them, for the view data's tests: what a template
 * reads of an item's fields, on its own page and on its card.
 */
final class Movies
{
    public const string RELEASE = '01KA41B000000000000000MV01';

    public const string PREMIERE = '01KA41B000000000000000MV02';

    public const string RUNTIME = '01KA41B000000000000000MV03';

    public const string RATING = '01KA41B000000000000000MV04';

    public const string CINEMAS = '01KA41B000000000000000MV05';

    public const string TRAILER = '01KA41B000000000000000MV06';

    public const string TAGLINE = '01KA41B000000000000000MV07';

    public const string NOTES = '01KA41B000000000000000MV08';

    public const string POSTER = '01KA41B000000000000000MV09';

    public const string SEQUEL = '01KA41B000000000000000MV10';

    public const string PREQUEL = '01KA41B000000000000000MV11';

    public const string BACKDROP = '01KA41B000000000000000MV12';

    /**
     * The movie type and its fields, in this order, each hidden from Show
     * on the site unless named in $show (handle => after, before or hidden);
     * with no feature that gives fields unless $supports names some.
     *
     * @param  array<string, string>  $show
     * @param  list<string>  $supports
     */
    public static function type(array $show = [], array $supports = []): void
    {
        if ($supports === []) {
            StoredFieldRows::movieType();
        } else {
            ctStoreRow(ctDefinition(['key' => 'movie', 'singular' => 'Movie', 'plural' => 'Movies', 'segment' => 'movies', 'icon' => 'film', 'taxonomies' => [], 'supports' => $supports]));
            app(ContentTypeRegistry::class)->refreshStored();
        }

        $fields = [
            [self::RELEASE, 'release_date', ['label' => 'Release date', 'fieldType' => 'datetime', 'settings' => ['dateOnly' => true]]],
            [self::PREMIERE, 'premiere', ['label' => 'Premiere', 'fieldType' => 'datetime']],
            [self::RUNTIME, 'runtime', ['label' => 'Runtime', 'fieldType' => 'number']],
            [self::RATING, 'rating', ['label' => 'Rating', 'fieldType' => 'select', 'settings' => ['choices' => [['value' => 'pg-13', 'label' => 'PG-13'], ['value' => 'r', 'label' => 'R']]]]],
            [self::CINEMAS, 'in_cinemas', ['label' => 'In cinemas', 'fieldType' => 'boolean']],
            [self::TRAILER, 'trailer_url', ['label' => 'Trailer URL', 'fieldType' => 'url']],
            [self::TAGLINE, 'tagline', ['label' => 'Tagline']],
            [self::NOTES, 'notes', ['label' => 'Notes', 'fieldType' => 'textarea']],
            [self::POSTER, 'poster', ['label' => 'Poster', 'fieldType' => 'media']],
            [self::SEQUEL, 'sequel', ['label' => 'Sequel', 'fieldType' => 'entry']],
            [self::PREQUEL, 'prequel', ['label' => 'Prequel', 'fieldType' => 'entry']],
            [self::BACKDROP, 'backdrop', ['label' => 'Backdrop', 'fieldType' => 'media']],
        ];

        foreach ($fields as $position => [$key, $handle, $entry]) {
            StoredFieldRows::store(StoredFieldRows::entry($key, $handle, [...$entry, 'show' => $show[$handle] ?? 'hidden']), $position + 1);
        }
    }

    /**
     * A movie holding these field values, by key, with a one-paragraph
     * body, published unless told not to.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function movie(User $actor, string $title, array $fields = [], bool $publish = true): Content
    {
        $movie = lifecycleDraft($actor, $title, 'movie')->refresh();
        app(SaveDraft::class)->execute(new SaveDraftInput($movie->id, $title, ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'The body.']]]]], $fields, $movie->working_revision_id, (string) $actor->id));

        if ($publish) {
            listsPublish($movie, $actor);
        }

        return $movie->refresh();
    }

    /**
     * A media field's stored value for this image.
     *
     * @return array{id: string, alt: string|null, decorative: bool}
     */
    public static function image(string $mediaId, ?string $alt = null): array
    {
        return ['id' => $mediaId, 'alt' => $alt, 'decorative' => false];
    }
}

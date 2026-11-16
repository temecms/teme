<?php

declare(strict_types=1);

namespace App\Domain\Cards\Types;

use App\Domain\Cards\CardAttribute;
use App\Domain\Cards\CardMenuEntry;
use App\Domain\Cards\CardReference;
use App\Domain\Cards\CardType;
use App\Domain\Cards\ChangeList;
use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\RegionCard;
use App\Domain\Cards\RenderContext;
use App\Domain\Regions\FeaturedPosts;
use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;

/**
 * A short list of posts in a theme's region: ones chosen by hand, in their
 * order, or the latest of a type, optionally filed under one term. Stored as
 * IDs and a count, and drawn when the page is (rule 7), by the theme's own
 * view when it has one, else core's.
 */
final class FeaturedPostsCard implements CardType, RegionCard
{
    /** The most posts one list shows. */
    public const int MOST = 12;

    /** A content or term ID, which is lowercase (I-418). */
    private const string ID = '/^[0-9a-hjkmnp-tv-z]{26}$/';

    public function name(): string
    {
        return 'featured-posts';
    }

    public function label(): string
    {
        return 'Featured posts';
    }

    public function description(): string
    {
        return 'A short list of posts: ones you choose, or the latest of a type, optionally filed under one category or tag.';
    }

    public function since(): string
    {
        return '0.9.0';
    }

    public function attributes(): array
    {
        return [
            'id' => new CardAttribute('id', ['required', 'string', 'regex:'.DocumentSchema::NODE_ID]),
            'heading' => new CardAttribute('heading', ['nullable', 'string', 'max:120']),
            'source' => new CardAttribute('source', ['in:chosen,latest'], 'latest'),
            'items' => new CardAttribute('items', ['array', 'list', 'max:'.self::MOST], []),
            'type' => new CardAttribute('type', ['string', 'max:64'], 'post'),
            'termId' => new CardAttribute('termId', ['nullable', 'string', 'regex:'.self::ID]),
            'count' => new CardAttribute('count', ['integer', 'min:1', 'max:'.self::MOST], 5),
            'images' => new CardAttribute('images', ['boolean'], true),
        ];
    }

    public function menu(): CardMenuEntry
    {
        return new CardMenuEntry('Featured posts', 'Cards', ['posts', 'list', 'latest', 'recent', 'featured', 'widget'], 'list', regionOnly: true);
    }

    public function renderHtml(array $attrs, RenderContext $context): string
    {
        if ($context->target !== 'web') {
            return '';
        }

        $items = app(FeaturedPosts::class)->items($attrs);

        if ($items === []) {
            return '';
        }

        $heading = trim((string) ($attrs['heading'] ?? ''));

        // A theme may draw the list its own way (docs/architecture/themes.md).
        $view = View::exists('theme::cards.featured-posts') ? 'theme::cards.featured-posts' : 'teme::cards.featured-posts';

        return view($view, [
            'heading' => $heading === '' ? null : $heading,
            'items' => $items,
            'images' => (bool) ($attrs['images'] ?? true),
        ])->render();
    }

    /**
     * Only the heading: the titles listed are other items' words.
     */
    public function plainText(array $attrs): string
    {
        return trim((string) ($attrs['heading'] ?? ''));
    }

    public function references(array $attrs): array
    {
        if (($attrs['source'] ?? 'latest') !== 'chosen') {
            return [];
        }

        return array_values(array_map(
            CardReference::content(...),
            array_filter((array) ($attrs['items'] ?? []), fn (mixed $id): bool => is_string($id) && $id !== ''),
        ));
    }

    /**
     * Chosen posts are content IDs, at least one; a latest list names a
     * registered public type.
     */
    public function validate(array $attrs): void
    {
        $items = (array) ($attrs['items'] ?? []);

        foreach ($items as $id) {
            if (! is_string($id) || preg_match(self::ID, $id) !== 1) {
                throw ValidationException::withMessages(['items' => __('Choose posts from this site.')]);
            }
        }

        if (($attrs['source'] ?? 'latest') === 'chosen') {
            if ($items === []) {
                throw ValidationException::withMessages(['items' => __('Choose up to 12 posts')]);
            }

            return;
        }

        $type = (string) ($attrs['type'] ?? 'post');
        $types = app(ContentTypeRegistry::class);

        if (! $types->has($type) || ! $types->get($type)->public) {
            throw ValidationException::withMessages(['type' => __('Choose a type the site shows.')]);
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function describeChange(array $before, array $after): string
    {
        $parts = [];

        if (ChangeList::differs($before, $after, 'heading')) {
            $parts[] = 'heading changed';
        }

        foreach (['source', 'items', 'type', 'termId', 'count'] as $key) {
            if (ChangeList::differs($before, $after, $key)) {
                $parts[] = 'posts listed changed';

                break;
            }
        }

        if (ChangeList::differs($before, $after, 'images')) {
            $parts[] = 'images changed';
        }

        return ChangeList::line($this->label(), $parts);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Cards\Types;

use App\Domain\Cards\CardAttribute;
use App\Domain\Cards\CardMenuEntry;
use App\Domain\Cards\CardType;
use App\Domain\Cards\ChangeList;
use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\Imported\CleanedMarkup;
use App\Domain\Cards\Imported\MarkupRule;
use App\Domain\Cards\Imported\MarkupTree;
use App\Domain\Cards\RenderContext;
use App\Domain\Media\ImageMarkup;
use Illuminate\Support\Str;

/**
 * Real writing from a WordPress site that the importer couldn't turn into
 * Teme's own blocks, kept as readers saw it and cleaned to one list (I-329).
 * Only the importer adds one; in the editor it is converted, kept or deleted.
 */
final class ImportedContentCard implements CardType
{
    /** Where a card's markup came from on the old site. */
    public const array SOURCES = ['html-block', 'block', 'shortcode', 'classic'];

    public function name(): string
    {
        return 'imported';
    }

    public function label(): string
    {
        return 'Imported content';
    }

    public function description(): string
    {
        return 'Content from the old WordPress site that the importer couldn’t turn into Teme’s own blocks, kept as readers saw it and cleaned. Only the importer adds one.';
    }

    public function since(): string
    {
        return '0.1.0';
    }

    public function attributes(): array
    {
        return [
            'id' => new CardAttribute('id', ['required', 'string', 'regex:'.DocumentSchema::NODE_ID]),
            // The list, checked on every save, an autosave's too (I-329).
            'markup' => new CardAttribute('markup', ['required', 'array', 'list', 'min:1', new MarkupRule]),
            'sourceKind' => new CardAttribute('sourceKind', ['required', 'in:'.implode(',', self::SOURCES)]),
            'sourceName' => new CardAttribute('sourceName', ['nullable', 'string', 'max:200']),
            'kept' => new CardAttribute('kept', ['boolean'], false),
        ];
    }

    /**
     * Never offered by + Insert or the slash menu: only the importer adds one.
     */
    public function menu(): CardMenuEntry
    {
        return new CardMenuEntry('Imported content', 'Cards', ['imported', 'wordpress', 'html'], 'import', inMenus: false);
    }

    public function renderHtml(array $attrs, RenderContext $context): string
    {
        $html = MarkupTree::html(
            $attrs['markup'] ?? [],
            $context,
            // Drawn as every picture in an article's body is; nothing once it has gone.
            fn (string $mediaId, string $alt): string => isset($context->media[$mediaId])
                ? app(ImageMarkup::class)->item($context->media[$mediaId], 'content', false, $alt)
                : '',
        );

        return $html === '' ? '' : '<div class="card-imported">'.$html.'</div>';
    }

    /**
     * Its words, so search, the word count and the import's fidelity check count them.
     */
    public function plainText(array $attrs): string
    {
        return MarkupTree::text($attrs['markup'] ?? []);
    }

    public function references(array $attrs): array
    {
        return MarkupTree::references($attrs['markup'] ?? []);
    }

    /**
     * The attributes' own rules, MarkupRule among them, say all there is to check.
     */
    public function validate(array $attrs): void {}

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function describeChange(array $before, array $after): string
    {
        $parts = [];

        if (ChangeList::differs($before, $after, 'markup')) {
            $parts[] = 'content changed';
        }

        $was = ($before['kept'] ?? false) === true;
        $is = ($after['kept'] ?? false) === true;

        if ($was !== $is) {
            $parts[] = $is ? 'kept' : 'no longer kept';
        }

        return ChangeList::line($this->label(), $parts);
    }

    /**
     * The node the importer puts in a document for this cleaned markup: a
     * new node ID, where it came from, and not yet kept.
     *
     * @return array{type: string, attrs: array<string, mixed>}
     */
    public static function node(CleanedMarkup $markup, string $sourceKind, ?string $sourceName = null): array
    {
        return ['type' => 'imported', 'attrs' => [
            'id' => (string) Str::ulid(),
            'markup' => $markup->parts,
            'sourceKind' => $sourceKind,
            'sourceName' => $sourceName,
            'kept' => false,
        ]];
    }
}

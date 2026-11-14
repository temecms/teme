<?php

declare(strict_types=1);

namespace App\Domain\Cards;

use App\Domain\Cards\Tiptap\LinkMark;
use App\Domain\Cards\Tiptap\TableCellNode;
use App\Domain\Cards\Tiptap\TableHeaderNode;
use Tiptap\Editor;
use Tiptap\Extensions\StarterKit;
use Tiptap\Extensions\TextAlign;
use Tiptap\Marks\Highlight;
use Tiptap\Marks\Subscript;
use Tiptap\Marks\Superscript;
use Tiptap\Marks\Underline;
use Tiptap\Nodes\Table;
use Tiptap\Nodes\TableRow;
use Tiptap\Nodes\TaskItem;
use Tiptap\Nodes\TaskList;

/**
 * Turns a stored document into HTML on the server. Runs of prose go to
 * tiptap-php; each card renders itself through its CardType. That keeps
 * card HTML entirely ours and limits what we ask of tiptap-php (study L,
 * §2.10). Nothing it produces is stored (rule 7).
 */
final readonly class DocumentRenderer
{
    public function __construct(private CardRegistry $cards, private CardMedia $media) {}

    /**
     * The document's HTML. Internal links resolve through the context's
     * LinkResolver, the bound one when no context is given. The media the
     * cards show is looked up here, in one query for the whole document.
     *
     * @param  array<mixed>  $document
     */
    public function html(array $document, ?RenderContext $context = null): string
    {
        $context ??= new RenderContext(app(LinkResolver::class));
        // Rebuilt with the document's media: the page's record and lead go along, or no card would see them.
        $context = new RenderContext($context->links, $context->target, $this->media->forDocument($document), $context->usage, $context->leadNodeId);
        $html = '';
        $run = [];

        foreach ((array) ($document['content'] ?? []) as $node) {
            $card = is_array($node) ? $this->cards->find((string) ($node['type'] ?? '')) : null;

            if ($card === null) {
                $run[] = $node;

                continue;
            }

            $html .= $this->prose($run, $context);
            $run = [];
            // For the document shell, which links only the styles of cards drawn (I-248).
            $context->usage?->record($card->name());
            $html .= $card->renderHtml($this->withDefaults($card, (array) ($node['attrs'] ?? [])), $context);
        }

        return $html.$this->prose($run, $context);
    }

    /**
     * @param  list<mixed>  $run
     */
    private function prose(array $run, RenderContext $context): string
    {
        if ($run === []) {
            return '';
        }

        // The PHP StarterKit has no Link and no Underline, unlike the
        // JavaScript one (study L, §2.10), so both are registered here.
        $editor = new Editor(['extensions' => [
            new StarterKit,
            new Underline,
            new Highlight,
            new Subscript,
            new Superscript,
            new TaskList,
            new TaskItem,
            new Table,
            new TableRow,
            new TableHeaderNode,
            new TableCellNode,
            new TextAlign(['types' => ['heading', 'paragraph']]),
            new LinkMark($context),
        ]]);

        return $editor->setContent(['type' => 'doc', 'content' => $run])->getHTML();
    }

    /**
     * The card's attributes, with the declared default for any it left out.
     *
     * @param  array<mixed>  $attrs
     *
     * @return array<string, mixed>
     */
    private function withDefaults(CardType $card, array $attrs): array
    {
        foreach ($card->attributes() as $name => $attribute) {
            if (! array_key_exists($name, $attrs) && $attribute->default !== null) {
                $attrs[$name] = $attribute->default;
            }
        }

        return $attrs;
    }
}

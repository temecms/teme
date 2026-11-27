<?php

/**
 * Turns a stored document into HTML on the server. Runs of prose go to
 * tiptap-php; each card renders itself through its CardType. That keeps
 * card HTML entirely ours and limits what we ask of tiptap-php (study L,
 * §2.10). Nothing it produces is stored (rule 7).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards;

use App\Domain\Cards\Tiptap\HeadingNode;
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
        $context = new RenderContext($context->links, $context->target, $this->media->forDocument($document), $context->usage, $context->leadNodeId, $context->insertions, $context->linkAttributes, $context->region, $context->headings ?? new HeadingIds);
        $html = '';
        $run = [];
        $content = array_values((array) ($document['content'] ?? []));
        $plan = $context->target === 'web' ? $context->insertions : null;
        $at = $plan?->at() ?? [];
        // The page's title is its level-1 heading; a card's headings sit one below the last heading before it.
        $heading = 1;

        foreach ($content as $index => $node) {
            if (isset($at[$index])) {
                $html .= $this->prose($run, $context);
                $run = [];
                $html .= implode('', $at[$index]);
            }

            $card = is_array($node) ? $this->cards->find((string) ($node['type'] ?? '')) : null;

            if ($card === null) {
                if (is_array($node) && ($node['type'] ?? null) === 'heading') {
                    $heading = (int) ($node['attrs']['level'] ?? 2);
                }

                $run[] = $node;

                continue;
            }

            $html .= $this->prose($run, $context);
            $run = [];
            // For the document shell, which links only the styles of cards drawn (I-248).
            $context->usage?->record($card->name());
            $attrs = $this->withDefaults($card, (array) ($node['attrs'] ?? []));
            $html .= $card instanceof OutlinedCard
                ? $card->renderInOutline($attrs, $context, max(2, min(6, $heading + 1)))
                : $card->renderHtml($attrs, $context);
        }

        $end = count($content);

        if (isset($at[$end])) {
            $html .= $this->prose($run, $context);
            $run = [];
            $html .= implode('', $at[$end]);
        }

        return $html.$this->prose($run, $context);
    }

    /**
     * The document's top-level blocks, each as the page draws it: its node
     * type, its attributes (a card's with its declared defaults) and its
     * HTML, from the same prose and card renderers html() uses, so a block's
     * HTML is the piece of the body it stands for. Plugin insertions are
     * not blocks: they are placed by html() alone.
     *
     * @param  array<mixed>  $document
     *
     * @return list<array{type: string, attrs: array<string, mixed>, html: string}>
     */
    public function blocks(array $document, ?RenderContext $context = null): array
    {
        $context ??= new RenderContext(app(LinkResolver::class));
        $context = new RenderContext($context->links, $context->target, $this->media->forDocument($document), $context->usage, $context->leadNodeId, null, $context->linkAttributes, $context->region, $context->headings ?? new HeadingIds);
        $editor = $this->editor($context);
        $blocks = [];
        $heading = 1;

        foreach (array_values((array) ($document['content'] ?? [])) as $node) {
            if (! is_array($node)) {
                continue;
            }

            $type = (string) ($node['type'] ?? '');
            $card = $this->cards->find($type);

            if ($card === null) {
                if ($type === 'heading') {
                    $heading = (int) ($node['attrs']['level'] ?? 2);
                }

                $blocks[] = ['type' => $type, 'attrs' => (array) ($node['attrs'] ?? []), 'html' => $editor->setContent(['type' => 'doc', 'content' => [$node]])->getHTML()];

                continue;
            }

            $context->usage?->record($card->name());
            $attrs = $this->withDefaults($card, (array) ($node['attrs'] ?? []));
            $blocks[] = ['type' => $type, 'attrs' => $attrs, 'html' => $card instanceof OutlinedCard
                ? $card->renderInOutline($attrs, $context, max(2, min(6, $heading + 1)))
                : $card->renderHtml($attrs, $context)];
        }

        return $blocks;
    }

    /**
     * @param  list<mixed>  $run
     */
    private function prose(array $run, RenderContext $context): string
    {
        if ($run === []) {
            return '';
        }

        return $this->editor($context)->setContent(['type' => 'doc', 'content' => $run])->getHTML();
    }

    /** The prose editor, drawing links as the context resolves them. */
    private function editor(RenderContext $context): Editor
    {
        // The PHP StarterKit has no Link and no Underline, unlike the
        // JavaScript one (study L, §2.10), so both are registered here.
        // Heading is ours: every heading gets a unique HTML id (issue 369).
        return new Editor(['extensions' => [
            new StarterKit(['heading' => false]),
            new HeadingNode($context->headings ?? new HeadingIds),
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

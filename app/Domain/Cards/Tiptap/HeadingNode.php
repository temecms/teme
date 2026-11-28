<?php

/**
 * A heading as the site draws it: tiptap-php's, with a unique HTML id from
 * its permanent node id when present, else from its words, so a theme can
 * link to it or build a contents list without parsing the body.
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards\Tiptap;

use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\HeadingIds;
use Tiptap\Nodes\Heading;
use Tiptap\Utils\HTML;

final class HeadingNode extends Heading
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(private readonly HeadingIds $ids, array $options = [])
    {
        parent::__construct($options);
    }

    /**
     * @param  object  $node
     * @param  array<string, mixed>  $HTMLAttributes
     *
     * @return array{0: string, 1: array<string, mixed>, 2: int}
     */
    public function renderHTML($node, $HTMLAttributes = []): array
    {
        $attrs = (array) ($node->attrs ?? []);
        $level = in_array($attrs['level'] ?? null, $this->options['levels'], true)
            ? (int) $attrs['level']
            : (int) $this->options['levels'][0];
        $text = self::textOf($node);
        $permanent = is_string($attrs['id'] ?? null) && preg_match(DocumentSchema::NODE_ID, (string) $attrs['id']) === 1
            ? (string) $attrs['id']
            : null;
        $id = $this->ids->assign($node, $permanent, $text, $level);

        return [
            "h{$level}",
            HTML::mergeAttributes($this->options['HTMLAttributes'], $HTMLAttributes, ['id' => $id]),
            0,
        ];
    }

    /** The heading's plain text, marks left out. */
    private static function textOf(object $node): string
    {
        $text = '';

        foreach ((array) ($node->content ?? []) as $child) {
            if (! is_object($child)) {
                continue;
            }

            if (isset($child->text)) {
                $text .= (string) $child->text;
            } elseif (isset($child->content)) {
                $text .= self::textOf($child);
            }
        }

        return $text;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert;

use App\Domain\Cards\DocumentSchema;
use App\Domain\Import\Convert\Html\Nodes;
use App\Domain\Import\Phases\SourceRef;

/**
 * Brings a converted document within what Teme stores (I-452): a list or a
 * quote nested deeper than DocumentSchema::MAX_DEPTH becomes its
 * paragraphs, in order, innermost first; the second pass's markers are
 * taken out and their places noted; and an empty document holds
 * one empty paragraph, as the editor's does.
 */
final class DocumentFit
{
    /** Nodes holding other blocks. */
    private const array CONTAINERS = ['bulletList', 'orderedList', 'listItem', 'blockquote', 'table', 'tableRow', 'tableHeader', 'tableCell', 'taskList', 'taskItem'];

    /** Containers that may give way to their paragraphs. */
    private const array FLATTENED = ['bulletList', 'orderedList', 'blockquote', 'table', 'taskList'];

    /** Containers the editor's schema won't have empty. */
    private const array NEVER_EMPTY = ['listItem', 'blockquote', 'tableHeader', 'tableCell'];

    /**
     * The document's blocks, fitted, and the references taken out of them.
     *
     * @param  list<array<string, mixed>>  $content
     *
     * @return array{content: list<array<string, mixed>>, references: list<PendingReference>}
     */
    public static function fit(array $content, ConversionReport $report): array
    {
        $references = [];
        $content = self::references(self::within($content, 1, $report), [], $references);

        return ['content' => $content === [] ? [Nodes::paragraph()] : $content, 'references' => $references];
    }

    /**
     * The blocks at this depth, the innermost containers fitted first, so a
     * container gives way to its paragraphs only when it still nests too
     * deep once everything inside it fits.
     *
     * @param  list<array<string, mixed>>  $nodes
     *
     * @return list<array<string, mixed>>
     */
    private static function within(array $nodes, int $depth, ConversionReport $report): array
    {
        $fitted = [];

        foreach ($nodes as $node) {
            $type = (string) ($node['type'] ?? '');

            if (in_array($type, self::CONTAINERS, true) && is_array($node['content'] ?? null)) {
                $node['content'] = self::within($node['content'], $depth + 1, $report);

                if ($type === 'listItem') {
                    $node = Nodes::listItem($node['content']);
                }
            }

            if (in_array($type, self::FLATTENED, true) && $depth + self::height($node) - 1 > DocumentSchema::MAX_DEPTH) {
                $report->count('block.too-deep');
                array_push($fitted, ...self::leaves($node));

                continue;
            }

            $fitted[] = $node;
        }

        return $fitted;
    }

    /**
     * How many levels the node spans, itself and its text included.
     *
     * @param  array<string, mixed>  $node
     */
    private static function height(array $node): int
    {
        $tallest = 0;

        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            $tallest = max($tallest, is_array($child) ? self::height($child) : 0);
        }

        return $tallest + 1;
    }

    /**
     * The textblocks and other blocks inside a container, in order; empty
     * paragraphs left out.
     *
     * @param  array<string, mixed>  $node
     *
     * @return list<array<string, mixed>>
     */
    private static function leaves(array $node): array
    {
        $leaves = [];

        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            $type = $child['type'] ?? null;

            if (in_array($type, self::CONTAINERS, true)) {
                array_push($leaves, ...self::leaves($child));
            } elseif ($type !== 'paragraph' || ! empty($child['content'])) {
                $leaves[] = $child;
            }
        }

        return $leaves;
    }

    /**
     * The nodes without the second pass's markers, each marker's place noted
     * as the index its content will have: the count of the nodes kept
     * before it in the same parent.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<int>  $path
     * @param  list<PendingReference>  $references
     *
     * @return list<array<string, mixed>>
     */
    private static function references(array $nodes, array $path, array &$references): array
    {
        $kept = [];

        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === Nodes::REFERENCE) {
                $references[] = new PendingReference(SourceRef::of((string) $node['attrs']['type'], (string) $node['attrs']['id']), [...$path, count($kept)]);

                continue;
            }

            if (is_array($node['content'] ?? null)) {
                $node['content'] = self::references($node['content'], [...$path, count($kept)], $references);

                if ($node['content'] === [] && in_array($node['type'] ?? null, self::NEVER_EMPTY, true)) {
                    $node['content'] = [Nodes::paragraph()];
                }
            }

            $kept[] = $node;
        }

        return $kept;
    }
}

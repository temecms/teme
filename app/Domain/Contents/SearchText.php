<?php

/**
 * Keeps an item's search columns equal to its published revision: the title
 * and plain text, and the words of its fields marked for search, while the
 * item is live and published, stemmed by its
 * language's rules (I-435), nothing otherwise, so a draft's words are never
 * found before they are public.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents;

use App\Domain\Contents\Models\Content;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Site\SiteLanguage;
use Illuminate\Support\Facades\DB;

final class SearchText
{
    /**
     * Brings one item's search_text and search_tsv into line. Call in every
     * content Operation's perform(), after the content row is saved.
     */
    public static function sync(Content $content): void
    {
        $published = ! $content->trashed() && $content->status === 'published' && $content->published_revision_id !== null
            ? DB::table('content_revisions')->where('id', $content->published_revision_id)->first(['title', 'plain_text', 'fields'])
            : null;

        if ($published === null) {
            DB::table('contents')->where('id', $content->id)->update(['search_text' => null, 'search_tsv' => null, 'search_config' => null]);

            return;
        }

        $title = (string) $published->title;
        $text = (string) $published->plain_text;
        $fields = (array) json_decode((string) ($published->fields ?? '{}'), true);
        $words = [];

        // Only the words search finds: snippets read search_text, the body's alone.
        foreach (app(FieldRegistry::class)->forType($content->type) as $field) {
            if ($field->search) {
                $words[] = trim($field->type->text($field, $fields[$field->key] ?? null));
            }
        }

        $extra = trim(implode(' ', array_filter($words, fn (string $word): bool => $word !== '')));
        $language = app(SiteLanguage::class);
        $config = $language->searchConfig($language->forContent($content));

        // search_config records the rules used, so a change of language knows what to rebuild.
        DB::statement(
            'UPDATE contents SET search_text = ?, search_tsv = to_tsvector(?::regconfig, coalesce(?, \'\')), search_config = ? WHERE id = ?',
            [$text, $config, $title.' '.$text.($extra === '' ? '' : ' '.$extra), $config, $content->id],
        );
    }
}

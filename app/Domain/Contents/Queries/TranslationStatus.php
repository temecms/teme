<?php

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use App\Domain\Contents\Models\Content;
use App\Domain\Languages\SiteLanguages;
use Illuminate\Support\Facades\DB;

/**
 * A translation group's versions and whether each is out of date (I-3360,
 * D9): worked out from the revision each was translated from, never stored.
 * A version is out of date when the item that revision belongs to, still
 * live, has published a newer revision since.
 */
final readonly class TranslationStatus
{
    public function __construct(private SiteLanguages $languages) {}

    /**
     * Every live version of the group, the default language's first, in one
     * statement: its ID, language (a tag), title, status, visibility, whether
     * it is out of date, and the item it was translated from, that item's
     * language and when it last published.
     *
     * @return list<array{id: string, language: string, title: string, status: string, visibility: string, outOfDate: bool, sourceId: string|null, sourceLanguage: string|null, sourceChangedAt: string|null}>
     */
    public function forGroup(string $group): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT c.id, c.language, c.title, c.status, c.visibility,
                   src.id AS source_id, src.language AS source_language, pub.created_at AS source_changed_at,
                   COALESCE(src.published_revision_id IS NOT NULL
                       AND src.published_revision_id <> sr.id
                       AND pub.created_at > sr.created_at, false) AS out_of_date
            FROM contents c
            JOIN content_revisions r ON r.id = c.working_revision_id
            LEFT JOIN content_revisions sr ON sr.id = r.source_revision_id
            LEFT JOIN contents src ON src.id = sr.content_id AND src.deleted_at IS NULL
            LEFT JOIN content_revisions pub ON pub.id = src.published_revision_id
            WHERE c.translation_group = ? AND c.deleted_at IS NULL
            ORDER BY c.language NULLS FIRST, c.id
            SQL, [$group]);

        return array_map(fn (object $row): array => [
            'id' => (string) $row->id,
            'language' => $this->languages->of($row->language === null ? null : (string) $row->language),
            'title' => (string) $row->title,
            'status' => (string) $row->status,
            'visibility' => (string) $row->visibility,
            'outOfDate' => (bool) $row->out_of_date,
            'sourceId' => $row->source_id === null ? null : (string) $row->source_id,
            'sourceLanguage' => $row->source_id === null ? null : $this->languages->of($row->source_language === null ? null : (string) $row->source_language),
            'sourceChangedAt' => $row->source_changed_at === null ? null : (string) $row->source_changed_at,
        ], $rows);
    }

    /**
     * The item's group's versions, as forGroup() gives them; none for an
     * item in no group.
     *
     * @return list<array{id: string, language: string, title: string, status: string, visibility: string, outOfDate: bool, sourceId: string|null, sourceLanguage: string|null, sourceChangedAt: string|null}>
     */
    public function forItem(Content $content): array
    {
        return $content->translation_group === null ? [] : $this->forGroup((string) $content->translation_group);
    }
}

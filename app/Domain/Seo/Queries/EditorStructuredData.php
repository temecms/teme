<?php

declare(strict_types=1);

namespace App\Domain\Seo\Queries;

use App\Domain\Addresses\Addresses;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Site\ArticleViews;
use App\Domain\Site\Document\DocumentMeta;
use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\Document\Withheld;
use App\Domain\Site\ViewModels\ArticleView;
use App\Domain\Themes\ViewIntent;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * What the gate would leave out of an item's page as one of its revisions
 * makes it (I-703), for the editor's check: each node, its types, and what
 * it lacks. A draft with no address yet is checked at the address it would
 * take, so the graph checked is the one it will publish. It reads only.
 */
final readonly class EditorStructuredData
{
    public function __construct(private ArticleViews $views, private DocumentMeta $meta) {}

    /**
     * The nodes left out, or null when the viewer may not edit the item.
     *
     * @return list<array{id: string, types: list<string>, missing: list<list<string>>}>|null
     */
    public function for(string $contentId, User $viewer, string $revisionId): ?array
    {
        $content = Content::query()->findOrFail($contentId);

        if (! Gate::forUser($viewer)->allows('update', $content)) {
            return null;
        }

        $view = $this->views->ofRevision($content->id, $revisionId);

        if ($view->url === '') {
            // Every public property of the view is a constructor parameter, so the copy differs in its address alone.
            $view = new ArticleView(...[...get_object_vars($view), 'url' => $this->wouldHave($content, $revisionId)]);
        }

        $head = $this->meta->for(new DocumentPage(new ViewIntent('single', type: $content->type), $view));

        return array_map(fn (Withheld $node): array => ['id' => $node->id, 'types' => $node->types, 'missing' => $node->missing], $head->withheld);
    }

    /**
     * The address the revision would publish at, site-relative, or '' for a
     * type with no public address; made now, never stored (rule 9).
     */
    private function wouldHave(Content $content, string $revisionId): string
    {
        $revision = ContentRevision::query()->where('content_id', $content->id)->whereKey($revisionId)->firstOrFail(['terms', 'fields']);
        $path = Addresses::wouldHave(
            $content,
            (string) $content->slug,
            array_map('strval', (array) $revision->terms),
            null,
            $content->published_at,
            ((array) $revision->fields)[BuiltInFields::PRIMARY_CATEGORY] ?? null,
        );

        return match (true) {
            $path === null => '',
            $path === '' => '/',
            default => '/'.$path.(Addresses::trailingSlash($content->type) ? '/' : ''),
        };
    }
}

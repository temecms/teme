<?php

declare(strict_types=1);

namespace Tests\Fixtures\Import;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Phases\Content\ContentPhase;
use App\Domain\Import\Phases\SecondPass\SecondPassStep;
use App\Models\User;

/**
 * Posts that use reusable blocks and link to one another, as the companion
 * sends them, and runs of the content phase with the import's later steps
 * alone (I-333).
 */
final class SecondPassImports
{
    /**
     * A reusable block (WordPress's wp_block) $id, its words one paragraph.
     *
     * @param  array<string, mixed>  $fields
     *
     * @return array<string, mixed>
     */
    public static function block(int $id, string $words = 'Open daily from nine.', array $fields = []): array
    {
        return ContentImports::wpItem($id, [
            'type' => 'wp_block', 'slug' => 'opening-hours', 'title' => 'Opening hours', 'permalink' => 'https://old.example/?p='.$id,
            'blocks' => [wpBlock('core/paragraph', "<p>{$words}</p>")],
            ...$fields,
        ]);
    }

    /**
     * Post $id, "Harbour news {id}" at /harbour-news-{id}/, with reusable block $block between its two paragraphs.
     *
     * @param  array<string, mixed>  $fields
     *
     * @return array<string, mixed>
     */
    public static function using(int $id, int $block, array $fields = []): array
    {
        return ContentImports::wpItem($id, [
            'slug' => 'harbour-news-'.$id, 'title' => 'Harbour news '.$id, 'permalink' => "https://old.example/harbour-news-{$id}/",
            'blocks' => [wpBlock('core/paragraph', '<p>Boats came in at dawn.</p>'), wpBlock('core/block', '', ['ref' => $block]), wpBlock('core/paragraph', '<p>The tide turned at noon.</p>')],
            ...$fields,
        ]);
    }

    /**
     * Post $id, "Harbour news {id}" at /harbour-news-{id}/, one paragraph linking to $href.
     *
     * @param  array<string, mixed>  $fields
     *
     * @return array<string, mixed>
     */
    public static function linking(int $id, string $href, array $fields = []): array
    {
        return ContentImports::wpItem($id, [
            'slug' => 'harbour-news-'.$id, 'title' => 'Harbour news '.$id, 'permalink' => "https://old.example/harbour-news-{$id}/",
            'blocks' => [wpBlock('core/paragraph', "<p>Read <a href=\"{$href}\">the later story</a>.</p>")],
            ...$fields,
        ]);
    }

    /**
     * The content phase and $steps alone (the second pass's step when none
     * are given), run to the end over these items as the operator, or $actor.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public static function run(FakeCompanion $companion, ImportSource $source, array $items, ImportRunKind $kind = ImportRunKind::Full, ?User $actor = null, object ...$steps): ImportRun
    {
        $companion->items('content', $items);
        importPhaseAlone(app(ContentPhase::class), ...($steps === [] ? [app(SecondPassStep::class)] : $steps));

        return importRun($source, $actor, $kind);
    }

    /**
     * The words of an item's working revision.
     */
    public static function words(Content $item): string
    {
        return (string) ContentRevision::query()->whereKey($item->refresh()->working_revision_id)->value('plain_text');
    }
}

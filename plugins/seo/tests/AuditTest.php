<?php

declare(strict_types=1);

namespace Teme\Seo\Tests;

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestDox;
use Teme\Seo\Tests\Support\SeoTestCase;

require_once __DIR__.'/Support/SeoTestCase.php';

/**
 * The site-wide audit page: which items fail which checks, and who may see it.
 */
final class AuditTest extends SeoTestCase
{
    private string $words = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->words = str_repeat('Roses need water and sun to grow well. ', 40);
    }

    #[TestDox('lists every published item failing a check, most problems first, filtered by problem')]
    public function test_it_lists_every_published_item_failing_a_check_most_problems_first_filtered_by_problem(): void
    {
        $good = seoWrite($this->editor, 'Feeding roses', [seoParagraph($this->words)], ['meta_description' => 'How and when to feed roses.']);
        seoWrite($this->editor, 'A garden guide', [seoParagraph($this->words, ['Feeding roses', $good], '.')], ['meta_description' => 'Everything about the garden.']);
        $poor = seoWrite($this->editor, 'A very long title about every single thing you could ever want to know', [seoParagraph('Too short.')]);
        seoWrite($this->editor, 'A draft with problems', publish: false);

        $this->signIn($this->editor);
        $this->get(route('plugins.area.show', ['code' => 'seo', 'page' => 'audit', 't' => ['problems' => ['filters' => ['check' => 'any']]]]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps('block_totals', fn (Assert $reload) => $reload
                    ->where('block_totals.items.0.value', 3)
                    ->where('block_totals.items.1.value', 3)
                    ->where('block_totals.items.2.value', 2))
                ->loadDeferredProps('block_problems', fn (Assert $reload) => $reload
                    ->where('block_problems.total', 3)
                    ->where('block_problems.rows.0.id', $poor->id)
                    ->where('block_problems.rows.0.cells.count.value', 5)
                    ->where('block_problems.rows.0.cells.problems.value', fn (string $problems): bool => str_starts_with($problems, 'No description, Title too long')
                        && ! str_contains($problems, 'No featured image'))));

        $this->get(route('plugins.area.show', ['code' => 'seo', 'page' => 'audit', 't' => ['problems' => ['filters' => ['check' => 'thin']]]]))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('block_problems', fn (Assert $reload) => $reload
                ->where('block_problems.total', 1)
                ->where('block_problems.rows.0.id', $poor->id)));
    }

    #[TestDox('counts posts without a featured image on their own, not as a problem')]
    public function test_it_counts_posts_without_a_featured_image_on_their_own_not_as_a_problem(): void
    {
        // Two posts that only fail the featured-image check: enough words, a description, and each links to the other.
        $first = seoWrite($this->editor, 'Feeding roses', [seoParagraph($this->words)], ['meta_description' => 'How and when to feed roses.']);
        $second = seoWrite($this->editor, 'A garden guide', [seoParagraph($this->words, ['Feeding roses', $first], '.')], ['meta_description' => 'Everything about the garden.']);
        DB::table('seo_links')->insert(['source_id' => $first->id, 'target_id' => $second->id, 'occurrences' => 1]);
        DB::table('seo_items')->where('id', $first->id)->update(['links_out' => 1]);

        $this->signIn($this->editor);
        $this->get(route('plugins.area.show', ['code' => 'seo', 'page' => 'audit', 't' => ['problems' => ['filters' => ['check' => 'any']]]]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps('block_featured', fn (Assert $reload) => $reload
                    ->where('block_featured.items.0.id', 'no-featured-image')
                    ->where('block_featured.items.0.value', 2))
                ->loadDeferredProps('block_totals', fn (Assert $reload) => $reload
                    ->where('block_totals.items.1.id', 'problems')
                    ->where('block_totals.items.1.value', 0))
                ->loadDeferredProps('block_problems', fn (Assert $reload) => $reload
                    ->where('block_problems.total', 0)));

        $this->get(route('plugins.area.show', ['code' => 'seo', 'page' => 'audit', 't' => ['problems' => ['filters' => ['check' => 'no-featured-image']]]]))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('block_problems', fn (Assert $reload) => $reload
                ->where('block_problems.total', 2)
                ->where('block_problems.rows.0.cells.problems.value', 'None')
                ->where('block_problems.rows.0.cells.count.value', 0)));
    }

    #[TestDox('counts an item thin by the fewest words the settings give')]
    public function test_it_counts_an_item_thin_by_the_fewest_words_the_settings_give(): void
    {
        seoWrite($this->editor, 'Feeding roses', [seoParagraph('Twenty words or so are here, which is quite enough for this little test of the thin setting.')]);
        $this->seo->changeSettings(['thin_words' => 50]);

        $this->signIn($this->editor);
        $this->get(route('plugins.area.show', ['code' => 'seo', 'page' => 'audit', 't' => ['problems' => ['filters' => ['check' => 'thin']]]]))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('block_problems', fn (Assert $reload) => $reload->where('block_problems.total', 1)));
    }

    #[TestDox('is closed to people without its permission')]
    public function test_it_is_closed_to_people_without_its_permission(): void
    {
        $this->signIn($this->person('author'));

        $this->get(route('plugins.area.show', ['code' => 'seo', 'page' => 'audit']))->assertForbidden();
    }
}

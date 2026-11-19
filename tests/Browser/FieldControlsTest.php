<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Fields\BuiltInFields;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server (TwoEditorsTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->harbour = lifecycleDraft($this->editor, 'Harbour news');
    $this->edit = "/admin/content/{$this->harbour->id}/edit";

    // A draft with all twelve fields set and a featured image whose library alt text is empty.
    $this->image = pageImage($this->editor, 1200, 630);
    $this->filled = app(CreateDraft::class)->execute(new CreateDraftInput('post', 'Ferry fares', ['type' => 'doc', 'content' => []], [
        BuiltInFields::EXCERPT => 'Dearer crossings from Monday.',
        BuiltInFields::SEO_TITLE => 'Ferry fares rise',
        BuiltInFields::META_DESCRIPTION => 'What the new fares cost.',
        BuiltInFields::SOCIAL_TITLE => 'Fares rise',
        BuiltInFields::SOCIAL_DESCRIPTION => 'The new fares.',
        BuiltInFields::NOINDEX => true,
        BuiltInFields::NOSNIPPET => true,
        BuiltInFields::MAX_SNIPPET => 120,
        BuiltInFields::NOARCHIVE => true,
        BuiltInFields::PAGE_TYPE => 'AboutPage',
        BuiltInFields::ARTICLE_TYPE => 'NewsArticle',
        BuiltInFields::FEATURED_IMAGE => ['id' => $this->image->id, 'alt' => '', 'decorative' => false],
    ], (string) $this->editor->id));
    $this->filledEdit = "/admin/content/{$this->filled->id}/edit";

    // Warm the screens before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get($this->edit)->assertOk();
    $this->actingAs($this->editor)->get($this->filledEdit)->assertOk();
});

// The twelve rows: each is the element's row, as the base draws it.
const FC_ROWS = <<<'JS'
(() => {
    const rows = {
        excerpt: '#editor-excerpt', featuredImage: '[data-test="featured-strip"], #editor-featured-choose',
        seoTitle: '#editor-seo-title', metaDescription: '#editor-seo-description',
        socialTitle: '#editor-social-title', socialDescription: '#editor-social-description',
        noindex: '#editor-seo-noindex', nosnippet: '#editor-nosnippet', maxSnippet: '#editor-max-snippet',
        noarchive: '#editor-noarchive', pageType: '#editor-page-type', articleType: '#editor-article-type',
    };
    // A row is the element's parent, the label and the hint with it; the featured image is its own section.
    const rowOf = (element, name) => name === 'featuredImage' ? element : element.closest('.grid') ?? element.parentElement;
    const clean = (node) => {
        const copy = node.cloneNode(true);
        // The counts measure text on a canvas, whose pixels differ between Windows and CI.
        for (const count of copy.querySelectorAll('[id$="-count"]')) {
            count.textContent = '';
        }
        for (const each of [copy, ...copy.querySelectorAll('*')]) {
            for (const attribute of [...each.attributes]) {
                // A media item's ID is made up in each run, and an image's address carries it.
                if (attribute.name === 'src') {
                    each.setAttribute('src', attribute.value.replace(/[0-9A-HJKMNP-TV-Z]{26}/, 'MEDIA'));
                }
                // Made-up ids differ run to run: Radix's, React 18's «r0» and :r0:, React 19's _r_0_. Nothing else may.
                if (attribute.name === 'aria-controls' || /radix-|«|:r[0-9a-z]+:|_r_[0-9a-z]*_?/.test(attribute.value)) {
                    each.removeAttribute(attribute.name);
                }
            }
        }
        return copy.outerHTML;
    };
    return Object.fromEntries(Object.entries(rows).map(([name, selector]) => {
        const element = document.querySelector(selector);
        return [name, element === null ? null : clean(rowOf(element, name))];
    }));
})()
JS;

it('draws the twelve built-in fields exactly as before, empty and filled', function (): void {
    expect($this->image->alt)->toBeNull();

    $rows = [];

    foreach (['empty' => $this->edit, 'filled' => $this->filledEdit] as $state => $address) {
        $page = cmOpenSearch(cmEditorSettled(cmSignIn($this->editor)->resize(1440, 900)->navigate($address)));
        $rows[$state] = $page->script(FC_ROWS);
    }

    $file = base_path('tests/Fixtures/Fields/built-in-controls.json');

    if (getenv('FIELDS_RECORD') === '1') {
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }

        file_put_contents($file, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    expect($rows)->toBe(json_decode((string) file_get_contents($file), true))
        ->and(array_filter([...$rows['empty'], ...array_values($rows['filled'])], fn (?string $row): bool => $row === null))->toBe([])
        ->and($rows['filled']['maxSnippet'])->toContain('disabled')
        ->and($rows['filled']['featuredImage'])->toContain('data-test="alt-missing"');
});

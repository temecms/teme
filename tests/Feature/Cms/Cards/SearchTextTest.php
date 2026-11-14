<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\UpdateContentDetails;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
});

// docSave() lives in tests/Pest.php (Task 2).

it('stores the plain text and the word count on the revision', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $saved = docSave($content, $this->author, docWith(docText('Three days of music by the quay.'),
        ['type' => 'callout', 'attrs' => ['id' => str_repeat('A', 26), 'tone' => 'note', 'text' => 'Tickets in July.']]));

    $revision = $saved->workingRevision;

    expect($revision->word_count)->toBe(10)
        ->and($revision->plain_text)->toContain('Tickets in July.')
        ->and($revision->kind)->toBe('save');
});

it('keeps the search text on the published revision, not the draft', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(docText('Lanterns on the water.')));
    listsPublish($content->refresh(), $this->author);
    docSave($content->refresh(), $this->author, docWith(docText('Fireworks over the bay.')));

    $row = DB::table('contents')->where('id', $content->id)->first(['search_text']);

    expect($row->search_text)->toContain('Lanterns')->and($row->search_text)->not->toContain('Fireworks');
});

it('finds an article by a word in its body', function (): void {
    $content = lifecycleDraft($this->author, 'Untitled news');
    docSave($content, $this->author, docWith(docText('The lifeboat crew marked fifty years of service.')));
    listsPublish($content->refresh(), $this->author);

    $found = DB::table('contents')->whereRaw("search_tsv @@ plainto_tsquery('english', ?)", ['lifeboat crew'])->pluck('id');

    expect($found)->toContain($content->id);
});

it('still finds the body after a quick edit changes the title', function (): void {
    $content = lifecycleDraft($this->author, 'Untitled news');
    docSave($content, $this->author, docWith(docText('The lifeboat crew marked fifty years of service.')));
    listsPublish($content->refresh(), $this->author);
    $content->refresh();

    app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput(
        $content->id, $content->working_revision_id, (string) $this->author->id, title: 'Fifty years afloat',
    ));

    $found = DB::table('contents')->whereRaw("search_tsv @@ plainto_tsquery('english', ?)", ['lifeboat'])->pluck('id');

    expect($content->refresh()->workingRevision->plain_text)->toContain('lifeboat')
        ->and($content->workingRevision->word_count)->toBe(8)
        ->and($found)->toContain($content->id);
});

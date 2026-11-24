<?php

declare(strict_types=1);

use App\Domain\Api\Llms\MarkdownDocument;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Feeds\FeedBody;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/*
 * An outside picture is an Image card at the old site's address, which only
 * an import writes while it brings the file (#716). A person may keep one,
 * change its words or replace it, but never add one.
 */

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
});

/**
 * An Image card drawn from the old site.
 *
 * @return array<string, mixed>
 */
function opCard(string $source, string $caption = 'The quay'): array
{
    return ['type' => 'image', 'attrs' => ['id' => str_repeat('A', 26), 'mediaId' => null, 'source' => $source, 'sourceWidth' => 1200, 'sourceHeight' => 800, 'alt' => 'Boats', 'caption' => $caption]];
}

/**
 * A post whose working revision holds the outside picture, as an import leaves it.
 */
function opImported(User $author, string $source): Content
{
    $content = app(CreateDraft::class)->execute(new CreateDraftInput('post', 'Harbour', docWith(docText('Boats')), [], (string) $author->id));
    ContentRevision::query()->whereKey($content->working_revision_id)->update(['document' => json_encode(docWith(opCard($source)))]);

    return $content->refresh();
}

it('keeps an outside picture the import left when a person saves, and lets its words change (#716)', function (): void {
    $content = opImported($this->author, 'https://old.example.org/harbour.jpg');

    $saved = docSave($content, $this->author, docWith(opCard('https://old.example.org/harbour.jpg', 'Morning on the quay')));

    expect(ContentRevision::query()->findOrFail($saved->working_revision_id)->document['content'][0]['attrs']['caption'])->toBe('Morning on the quay');
});

it('refuses an outside picture a person adds, saving nothing (#716)', function (): void {
    $content = opImported($this->author, 'https://old.example.org/harbour.jpg');
    $revisions = ContentRevision::query()->count();

    expect(fn () => docSave($content, $this->author, docWith(opCard('https://old.example.org/harbour.jpg'), opCard('https://elsewhere.example/cat.jpg'))))
        ->toThrow(ValidationException::class, 'An image from the old site stays only as the import left it: choose another from the library.')
        ->and(ContentRevision::query()->count())->toBe($revisions);
});

it('refuses a new item holding an outside picture, creating nothing (#716)', function (): void {
    expect(fn () => app(CreateDraft::class)->execute(new CreateDraftInput('post', 'Cat', docWith(opCard('https://elsewhere.example/cat.jpg')), [], (string) $this->author->id)))
        ->toThrow(ValidationException::class, 'An image from the old site stays only as the import left it: choose another from the library.')
        ->and(Content::query()->count())->toBe(0);
});

it('writes an outside picture into a feed and into Markdown at the old site\'s address (#716)', function (): void {
    $document = docWith(opCard('https://old.example.org/harbour.jpg'));

    expect(app(FeedBody::class)->html($document))->toBe('<figure><img src="https://old.example.org/harbour.jpg" alt="Boats"><figcaption>The quay</figcaption></figure>')
        ->and(MarkdownDocument::of($document))->toContain('![Boats](https://old.example.org/harbour.jpg)');
});

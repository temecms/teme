<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\PluginWords;
use App\Domain\Plugins\Readers\ImportReaderDeclaration;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Import\HasImportReader;
use Teme\Contracts\Import\ImportItem;
use Teme\Contracts\Import\ImportKind;
use Teme\Contracts\Import\ImportReaderRefused;
use Teme\Contracts\Plugins\Plugin;
use Teme\Testing\FakeImportReader;
use Tests\Fixtures\Plugins\Readers\Readers;

require_once dirname(__DIR__, 2).'/Fixtures/plugins/readers/src/Readers.php';

/**
 * Writes a fake export file with the given content and returns its path.
 *
 * @param  array<string, mixed>  $content
 */
function fakeExport(array $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'fake-export');
    file_put_contents($path, json_encode($content, JSON_THROW_ON_ERROR));

    return $path;
}

/**
 * A fake export of a post, a page, a term, a person and a media item.
 *
 * @return array<string, mixed>
 */
function fakeSite(): array
{
    return [
        'system' => 'Fake export',
        'version' => '2',
        'site' => 'https://old.example.com',
        'languages' => ['en'],
        'items' => [
            ['kind' => ImportKind::PERSON, 'id' => 'p1', 'login' => 'ada', 'email' => 'ada@example.com', 'displayName' => 'Ada'],
            ['kind' => ImportKind::TERM, 'id' => 't1', 'taxonomy' => 'category', 'name' => 'News', 'slug' => 'news'],
            ['kind' => ImportKind::MEDIA, 'id' => 'm1', 'url' => 'https://old.example.com/a.jpg', 'mime' => 'image/jpeg', 'alt' => 'A'],
            ['kind' => ImportKind::POST, 'id' => 'i1', 'title' => 'Hello', 'slug' => 'hello', 'status' => 'published', 'publishedAt' => '2026-01-02 03:04:05', 'oldAddress' => 'https://old.example.com/hello/', 'content' => '<p>Hi</p>', 'authorId' => 'p1', 'termIds' => ['t1']],
            ['kind' => ImportKind::PAGE, 'id' => 'i2', 'title' => 'About', 'slug' => 'about', 'status' => 'draft', 'content' => 'About us'],
        ],
    ];
}

it('names the import.read capability a reader plugin asks for, in plain words', function (): void {
    expect(PluginWords::capability('import.read'))->toBe('Read another system’s export file, so its content can be imported into your site');
});

it('refuses import readers without import.read, or not well formed', function (): void {
    $validator = app(ContributionsValidator::class);
    $reader = ['id' => 'fake-export', 'label' => 'Fake export', 'fileTypes' => ['json']];

    expect($validator->errors(['importReaders' => [$reader]], '1.0.0', ['content.read']))
        ->toBe(['It adds import readers, but the plugin doesn’t ask for import.read.'])
        ->and($validator->errors(['importReaders' => [$reader]], '1.0.0', ['import.read']))->toBe([])
        ->and($validator->errors(['importReaders' => [['id' => 'Fake Export', 'label' => 'Fake', 'fileTypes' => ['json']]]], '1.0.0', ['import.read']))->toHaveCount(1)
        ->and($validator->errors(['importReaders' => [['id' => 'fake', 'label' => 'Fake', 'fileTypes' => ['.json']]]], '1.0.0', ['import.read']))->toHaveCount(1)
        ->and($validator->errors(['importReaders' => [$reader, $reader]], '1.0.0', ['import.read']))->toHaveCount(1)
        ->and($validator->errors(['importReaders' => [$reader, [...$reader, 'id' => 'other', 'secret' => 'x']]], '1.0.0', ['import.read']))->toHaveCount(1)
        ->and($validator->errors(['importReaders' => []], '1.0.0', ['import.read']))->toHaveCount(1)
        ->and($validator->errors(['importReaders' => array_fill(0, 6, $reader)], '1.0.0', ['import.read']))->toHaveCount(1);
});

it('refuses a plugin whose class and declared readers don’t match', function (): void {
    $plain = new class implements Plugin
    {
        public function register(Hooks $hooks): void {}
    };

    expect(ImportReaderDeclaration::startErrors(['importReaders' => []], $plain))
        ->toBe(['It declares import readers but doesn’t implement '.HasImportReader::class.'.'])
        ->and(ImportReaderDeclaration::startErrors([], new Readers))
        ->toBe(['It hands over import readers but declares none.'])
        ->and(ImportReaderDeclaration::startErrors(['importReaders' => []], new Readers))
        ->toBe([]);
});

it('probes a fake export: its system, languages and counts of each kind', function (): void {
    $path = fakeExport(fakeSite());
    $probe = (new FakeImportReader)->probe($path);

    expect($probe->system)->toBe('Fake export')
        ->and($probe->version)->toBe('2')
        ->and($probe->siteAddress)->toBe('https://old.example.com')
        ->and($probe->languages)->toBe(['en'])
        ->and($probe->counts)->toBe([ImportKind::PERSON => 1, ImportKind::TERM => 1, ImportKind::MEDIA => 1, ImportKind::POST => 1, ImportKind::PAGE => 1]);

    unlink($path);
});

it('lists a fake export a page at a time, in order, and fetches one item by its ID', function (): void {
    $path = fakeExport(fakeSite());
    $reader = new FakeImportReader;

    $first = $reader->page($path, null, 2);
    $second = $reader->page($path, $first->nextCursor, 2);
    $last = $reader->page($path, $second->nextCursor, 2);

    expect(array_map(fn ($item) => $item->id, $first->items))->toBe(['p1', 't1'])
        ->and($first->nextCursor)->toBe('2')
        ->and(array_map(fn ($item) => $item->id, $second->items))->toBe(['m1', 'i1'])
        ->and(array_map(fn ($item) => $item->id, $last->items))->toBe(['i2'])
        ->and($last->nextCursor)->toBeNull()
        ->and($reader->fetch($path, 'i1'))
        ->kind->toBe(ImportKind::POST)
        ->title->toBe('Hello')
        ->status->toBe('published')
        ->publishedAt->toBe('2026-01-02 03:04:05')
        ->oldAddress->toBe('https://old.example.com/hello/')
        ->authorId->toBe('p1')
        ->termIds->toBe(['t1']);

    unlink($path);
});

it('carries a person, a term and a media item’s fields in the neutral shape', function (): void {
    $path = fakeExport(fakeSite());
    $reader = new FakeImportReader;

    expect($reader->fetch($path, 'p1')->fields)->toBe(['login' => 'ada', 'email' => 'ada@example.com', 'displayName' => 'Ada'])
        ->and($reader->fetch($path, 't1')->fields)->toBe(['taxonomy' => 'category', 'name' => 'News', 'description' => ''])
        ->and($reader->fetch($path, 'm1')->fields)->toBe(['url' => 'https://old.example.com/a.jpg', 'mime' => 'image/jpeg', 'alt' => 'A']);

    unlink($path);
});

it('refuses a file that isn’t a fake export, an item of an unknown kind or status, and a missing item', function (): void {
    $reader = new FakeImportReader;

    $notJson = tempnam(sys_get_temp_dir(), 'fake-export');
    file_put_contents($notJson, '<rss></rss>');

    expect(fn () => $reader->probe($notJson))->toThrow(ImportReaderRefused::class, 'This file isn’t a fake export: it isn’t JSON.');

    $unknownKind = fakeSite();
    $unknownKind['items'][] = ['kind' => 'widget', 'id' => 'w1'];
    $badKind = fakeExport($unknownKind);

    expect(fn () => $reader->probe($badKind))->toThrow(ImportReaderRefused::class, 'An item of this file has no known kind or no ID.');

    $badStatus = fakeSite();
    $badStatus['items'][] = ['kind' => ImportKind::POST, 'id' => 'i9', 'status' => 'pending-review'];
    $statusPath = fakeExport($badStatus);

    expect(fn () => $reader->probe($statusPath))->toThrow(ImportReaderRefused::class, 'An item of this file has a status Teme doesn’t know.');

    $path = fakeExport(fakeSite());

    expect(fn () => $reader->fetch($path, 'nope'))->toThrow(ImportReaderRefused::class, 'This file has no item with that ID.');

    foreach ([$notJson, $badKind, $statusPath, $path] as $file) {
        unlink($file);
    }
});

it('carries a comment’s post and spam status, and a menu item’s menu, type, object and order, and no place for a menu', function (): void {
    $path = fakeExport([
        'system' => 'Fake export',
        'items' => [
            ['kind' => ImportKind::POST, 'id' => 'i1', 'title' => 'Hello', 'status' => 'published'],
            ['kind' => ImportKind::COMMENT, 'id' => 'c1', 'postId' => 'i1', 'status' => 'spam', 'content' => 'Buy now', 'authorName' => 'Sam', 'authorEmail' => 'sam@example.com', 'authorUrl' => 'https://example.com', 'authorIp' => '192.0.2.1'],
            ['kind' => ImportKind::MENU, 'id' => 'm1', 'title' => 'Main', 'slug' => 'main'],
            ['kind' => ImportKind::MENU_ITEM, 'id' => 'mi1', 'menuId' => 'm1', 'itemType' => 'content', 'objectId' => 'i1', 'order' => 1, 'title' => 'Hello'],
        ],
    ]);
    $reader = new FakeImportReader;

    $comment = $reader->fetch($path, 'c1');
    $menu = $reader->fetch($path, 'm1');
    $item = $reader->fetch($path, 'mi1');

    expect($comment->postId)->toBe('i1')
        ->and($comment->status)->toBe('spam')
        ->and($comment->fields)->toBe(['authorName' => 'Sam', 'authorEmail' => 'sam@example.com', 'authorUrl' => 'https://example.com', 'authorIp' => '192.0.2.1'])
        ->and($menu->fields)->toBe(['slug' => 'main'])
        ->and($item->kind)->toBe(ImportKind::MENU_ITEM)
        ->and($item->menuId)->toBe('m1')
        ->and($item->itemType)->toBe('content')
        ->and($item->objectId)->toBe('i1')
        ->and($item->order)->toBe(1);

    unlink($path);
});

it('carries no menu placement, since a menu comes across unplaced', function (): void {
    expect(property_exists(ImportItem::class, 'placement'))->toBeFalse();
});

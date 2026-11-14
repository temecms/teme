<?php

declare(strict_types=1);

use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TaxonomyRegistry;
use App\Providers\TypesServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('registers what phase 1 has, in order', function (): void {
    expect(app(ContentTypeRegistry::class)->keys())->toBe(['post', 'page', 'region'])
        ->and(collect(app(TaxonomyRegistry::class)->forType('post'))->pluck('key')->all())->toBe(['category', 'tag'])
        ->and(app(TaxonomyRegistry::class)->forType('page'))->toBe([])
        ->and(app(TaxonomyRegistry::class)->get('category')->hierarchical)->toBeTrue()
        ->and(app(TaxonomyRegistry::class)->get('tag')->hierarchical)->toBeFalse();
});

it('defaults a type\'s abilities to the global content abilities', function (): void {
    $post = app(ContentTypeRegistry::class)->get('post');

    expect($post->ability('publish'))->toBe('content.publish')
        ->and($post->ability('edit.any'))->toBe('content.edit.any');

    $own = new ContentType('event', 'Event', 'Events', 'events', abilities: ['publish' => 'events.publish']);
    expect($own->ability('publish'))->toBe('events.publish')
        ->and($own->ability('create'))->toBe('content.create');
});

it('refuses keys, segments and abilities that would break something', function (callable $register, string $message): void {
    expect($register)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'bad key' => [fn () => app(ContentTypeRegistry::class)->register(new ContentType('Event!', 'E', 'Es', 'events')), 'key'],
    'duplicate key' => [fn () => app(ContentTypeRegistry::class)->register(new ContentType('post', 'P', 'Ps', 'other-posts')), 'already registered'],
    'reserved segment' => [fn () => app(ContentTypeRegistry::class)->register(new ContentType('file', 'File', 'Files', 'media')), 'reserved'],
    'the navigation screen\'s segment' => [fn () => app(ContentTypeRegistry::class)->register(new ContentType('menu', 'Menu', 'Menus', 'navigation')), 'reserved'],
    'the regions screen\'s segment' => [fn () => app(ContentTypeRegistry::class)->register(new ContentType('block', 'Block', 'Blocks', 'regions')), 'reserved'],
    'the comments screen\'s segment' => [fn () => app(ContentTypeRegistry::class)->register(new ContentType('remark', 'Remark', 'Remarks', 'comments')), 'reserved'],
    'the tools screen\'s segment' => [fn () => app(ContentTypeRegistry::class)->register(new ContentType('tool', 'Tool', 'Tools', 'tools')), 'reserved'],
    'segment a taxonomy uses' => [fn () => app(ContentTypeRegistry::class)->register(new ContentType('group', 'Group', 'Groups', 'categories')), 'segment'],
    'unknown action' => [fn () => app(ContentTypeRegistry::class)->register(new ContentType('event', 'Event', 'Events', 'events', abilities: ['fly' => 'x'])), 'action'],
]);

it('refuses a type that names a taxonomy nobody registered', function (): void {
    typesFresh();
    app(ContentTypeRegistry::class)->register(new ContentType('event', 'Event', 'Events', 'events', taxonomies: ['topic']));

    expect(fn () => app(TypesServiceProvider::class, ['app' => app()])->verify())
        ->toThrow(InvalidArgumentException::class, 'topic');
});

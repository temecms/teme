<?php

declare(strict_types=1);

use App\Domain\Fields\BuiltInFields;
use App\Domain\Site\Insertions\InsertionPoints;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Insertions\ArticleFacts;
use Teme\Contracts\Insertions\InsertionContributor;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    pageArticle($this->editor, 'Harbour story', words: 40, excerpt: 'On the quay.');
});

it('gives article contributors the title and social fields', function (): void {
    $item = pageArticle($this->editor, 'Dawn ferry', words: 20, excerpt: 'Leaves at six.');
    docSave($item, $this->editor, docWith(docText(str_repeat('word ', 20))), fields: [
        BuiltInFields::EXCERPT => 'Leaves at six.',
        BuiltInFields::SOCIAL_TITLE => 'Ferry at dawn',
        BuiltInFields::SOCIAL_DESCRIPTION => 'The first crossing.',
    ]);
    listsPublish($item, $this->editor);

    $seen = (object) ['items' => []];
    app()->instance('test.insertions.facts', new readonly class($seen) implements InsertionContributor
    {
        public function __construct(private object $seen) {}

        public function precedence(): string
        {
            return 'core';
        }

        public function insertions(ArticleFacts $article): array
        {
            $this->seen->items[] = [$article->title, $article->socialTitle, $article->socialDescription, $article->excerpt, $article->path];

            return [];
        }
    });
    app()->tag(['test.insertions.facts'], InsertionPoints::CONTRIBUTORS);

    addrGet('/dawn-ferry/')->assertOk();

    expect($seen->items)->toBe([['Dawn ferry', 'Ferry at dawn', 'The first crossing.', 'Leaves at six.', '/dawn-ferry/']]);
});

it('gives null social fields and excerpt when none were set', function (): void {
    pageArticle($this->editor, 'Bare facts', words: 20);
    $seen = (object) ['items' => []];
    app()->instance('test.insertions.empty-social', new readonly class($seen) implements InsertionContributor
    {
        public function __construct(private object $seen) {}

        public function precedence(): string
        {
            return 'core';
        }

        public function insertions(ArticleFacts $article): array
        {
            $this->seen->items[] = [$article->title, $article->socialTitle, $article->socialDescription, $article->excerpt];

            return [];
        }
    });
    app()->tag(['test.insertions.empty-social'], InsertionPoints::CONTRIBUTORS);

    addrGet('/bare-facts/')->assertOk();

    expect($seen->items)->toBe([['Bare facts', null, null, null]]);
});

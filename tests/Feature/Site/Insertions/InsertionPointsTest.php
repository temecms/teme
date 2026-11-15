<?php

declare(strict_types=1);

use App\Domain\Site\Insertions\InsertionPoints;
use Teme\Contracts\Insertions\ArticleFacts;
use Teme\Contracts\Insertions\Insertion;
use Teme\Contracts\Insertions\InsertionContributor;
use Teme\Contracts\Insertions\Position;
use Teme\Contracts\Plugins\Capability;

it('asks a contributor registered under the tag with the article\'s facts', function (): void {
    $holder = new class
    {
        public ?ArticleFacts $seen = null;
    };
    $facts = new ArticleFacts('id1', 'post', ['category' => ['t1']], 'en', ['a1'], 120, 5);

    $name = 'test.insertions.'.uniqid();
    app()->instance($name, new readonly class($holder) implements InsertionContributor
    {
        public function __construct(private object $holder) {}

        public function precedence(): string
        {
            return 'plugin';
        }

        public function insertions(ArticleFacts $article): array
        {
            $this->holder->seen = $article;

            return [new Insertion('ad.after-2', Position::afterParagraph(2), '<aside data-test="ad">Ad</aside>')];
        }
    });
    app()->tag([$name], InsertionPoints::CONTRIBUTORS);

    $insertions = app(InsertionPoints::class)->for($facts);

    expect($holder->seen)->toEqual($facts)
        ->and($insertions)->toHaveCount(1)
        ->and($insertions[0]->key)->toBe('ad.after-2')
        ->and($insertions[0]->html)->toBe('<aside data-test="ad">Ad</aside>');
});

it('keeps the first insertion for a key, reading plugin, then site, then core', function (): void {
    $facts = new ArticleFacts('id1', 'post', [], 'en', [], 10, 2);

    foreach ([
        ['core', '<div data-test="core">C</div>'],
        ['site', '<div data-test="site">S</div>'],
        ['plugin', '<div data-test="plugin">P</div>'],
    ] as [$precedence, $html]) {
        $name = 'test.insertions.'.$precedence.'.'.uniqid();
        app()->instance($name, new readonly class($precedence, $html) implements InsertionContributor
        {
            public function __construct(private string $precedence, private string $html) {}

            public function precedence(): string
            {
                return $this->precedence;
            }

            public function insertions(ArticleFacts $article): array
            {
                return [new Insertion('shared', Position::beforeContent(), $this->html)];
            }
        });
        app()->tag([$name], InsertionPoints::CONTRIBUTORS);
    }

    $insertions = app(InsertionPoints::class)->for($facts);

    expect($insertions)->toHaveCount(1)
        ->and($insertions[0]->html)->toBe('<div data-test="plugin">P</div>');
});

it('does not bind a contributor when the plugin was not granted content.insert', function (): void {
    $holder = new class
    {
        public bool $asked = false;
    };

    $contributor = new readonly class($holder) implements InsertionContributor
    {
        public function __construct(private object $holder) {}

        public function precedence(): string
        {
            return 'plugin';
        }

        public function insertions(ArticleFacts $article): array
        {
            $this->holder->asked = true;

            return [new Insertion('should-not', Position::beforeContent(), '<div>no</div>')];
        }
    };

    app(InsertionPoints::class)->bind($contributor, []);

    $insertions = app(InsertionPoints::class)->for(new ArticleFacts('id1', 'post', [], 'en', [], 1, 1));

    expect($holder->asked)->toBeFalse()
        ->and($insertions)->toBe([]);

    app(InsertionPoints::class)->bind($contributor, [Capability::ContentInsert->value]);

    $insertions = app(InsertionPoints::class)->for(new ArticleFacts('id1', 'post', [], 'en', [], 1, 1));

    expect($holder->asked)->toBeTrue()
        ->and($insertions)->toHaveCount(1)
        ->and(Capability::ContentInsert->value)->toBe('content.insert');
});

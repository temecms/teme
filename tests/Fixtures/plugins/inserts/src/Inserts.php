<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Inserts;

use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Insertions\ArticleFacts;
use Teme\Contracts\Insertions\Insertion;
use Teme\Contracts\Insertions\InsertionContributor;
use Teme\Contracts\Insertions\Position;
use Teme\Contracts\Plugins\Plugin;

/**
 * Places a marked aside after the second paragraph of every article.
 */
final class Inserts implements InsertionContributor, Plugin
{
    public function register(Hooks $hooks): void
    {
        //
    }

    public function precedence(): string
    {
        return 'plugin';
    }

    public function insertions(ArticleFacts $article): array
    {
        return [
            new Insertion('inserts.after-2', Position::afterParagraph(2), '<aside data-test="insertion">Inserted</aside>'),
        ];
    }
}

<?php

declare(strict_types=1);

use Teme\Contracts\StructuredData\NodeIds;

it('names the fragments core\'s nodes end their @id with, and a card\'s node by its type and ID (I-696)', function (): void {
    expect([NodeIds::WEBSITE, NodeIds::ORGANIZATION, NodeIds::LOGO, NodeIds::ARTICLE, NodeIds::PRIMARY_IMAGE, NodeIds::BREADCRUMB, NodeIds::PERSON])
        ->toBe(['#website', '#organization', '#logo', '#article', '#primaryimage', '#breadcrumb', '#person'])
        ->and(NodeIds::card('video', '01K6A0P1CE000000000000VD01'))->toBe('#video-01K6A0P1CE000000000000VD01');
});

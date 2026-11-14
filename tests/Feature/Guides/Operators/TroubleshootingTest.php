<?php

declare(strict_types=1);

use App\Domain\Deployment\Health\Doctor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Guides\OperatorGuide;

uses(RefreshDatabase::class);

it('has a heading for every check the doctor runs, under its own name', function (): void {
    $titles = array_values(array_unique(array_map(fn ($result): string => $result->title, app(Doctor::class)->examine())));
    $headings = OperatorGuide::page('troubleshooting.md')->headingsAt(3);

    expect($titles)->not->toBe([])
        ->and(array_values(array_diff($titles, $headings)))->toBe([]);
});

it('says what the health address answers, and how to read the doctor', function (): void {
    $page = OperatorGuide::page('troubleshooting.md');

    expect($page->text())->toContain('/up')
        ->and($page->text())->toContain('503')
        ->and(array_column(OperatorGuide::commands($page), 'name'))->toContain('teme:doctor');
});

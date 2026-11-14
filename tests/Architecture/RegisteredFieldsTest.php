<?php

declare(strict_types=1);

use App\Domain\Fields\FieldRegistry;
use Tests\TestCase;

uses(TestCase::class);

it('documents every registered field', function (): void {
    foreach (app(FieldRegistry::class)->all() as $field) {
        expect(trim($field->label))->not->toBe('', "[{$field->handle}] has no label.")
            ->and(trim($field->description))->not->toBe('', "[{$field->handle}] has no description.")
            ->and($field->since)->toMatch('/^\d+\.\d+\.\d+$/', "[{$field->handle}] has no since version.");
    }
});

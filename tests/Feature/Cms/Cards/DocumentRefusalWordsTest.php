<?php

declare(strict_types=1);

use App\Domain\Cards\Exceptions\InvalidDocumentException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('refuses a block it doesn’t know in words without the document’s path, and keeps the path on the refusal', function (): void {
    expect(fn () => docValidate(docWith(['type' => 'widget', 'attrs' => []])))->toThrow(function (InvalidDocumentException $refusal): void {
        expect($refusal->errors()['document'][0])->toBe('Teme doesn’t know the “widget” block, so it can’t save it.')
            ->and($refusal->path)->toBe('content.0.type');
    });
});

it('refuses a setting a block doesn’t have in words without the document’s path', function (): void {
    expect(fn () => docValidate(docWith(['type' => 'paragraph', 'attrs' => ['colour' => 'red'], 'content' => [['type' => 'text', 'text' => 'Tide']]])))->toThrow(function (InvalidDocumentException $refusal): void {
        expect($refusal->errors()['document'][0])->toBe('Teme can’t save the “colour” setting here.')
            ->and($refusal->path)->toBe('content.0.attrs.colour');
    });
});

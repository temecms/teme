<?php

declare(strict_types=1);

use Teme\Contracts\Import\ImportOutcome;
use Teme\Contracts\Import\ImportScope;
use Teme\Contracts\Import\WordPressImporter;
use Teme\Contracts\Import\WordPressRecord;

it('publishes each import contract with its @since and nothing from a framework', function (string $class): void {
    $reflection = new ReflectionClass($class);
    $types = [];

    expect($reflection->getDocComment())->toContain('@since 1.0.0');

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class) {
            continue;
        }

        expect($method->getDocComment())->toContain('@since 1.0.0');
        $types[] = $method->getReturnType();

        foreach ($method->getParameters() as $parameter) {
            $types[] = $parameter->getType();
        }
    }

    foreach (array_filter($types) as $type) {
        foreach ($type instanceof ReflectionNamedType ? [$type] : $type->getTypes() as $one) {
            if (! $one->isBuiltin() && ! in_array($one->getName(), ['self', 'static'], true)) {
                expect($one->getName())->toStartWith('Teme\\Contracts\\');
            }
        }
    }
})->with([WordPressImporter::class, WordPressRecord::class, ImportScope::class, ImportOutcome::class]);

it('carries a record’s kind, WordPress ID, type, fields and declared meta, in that order', function (): void {
    $record = new WordPressRecord(WordPressRecord::POST, 7, 'ap_actor', ['title' => 'Ada'], ['_importer_inbox' => ['https://example.com/inbox']]);

    expect(array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), (new ReflectionMethod(WordPressRecord::class, '__construct'))->getParameters()))
        ->toBe(['kind', 'sourceId', 'type', 'fields', 'meta'])
        ->and($record->sourceId)->toBe(7)
        ->and($record->meta['_importer_inbox'])->toBe(['https://example.com/inbox']);
});

it('says an outcome as imported, skipped with why, or failed with a sentence, and never without words', function (): void {
    expect(ImportOutcome::imported())->status->toBe(ImportOutcome::IMPORTED)->words->toBe('')
        ->and(ImportOutcome::skipped('A test account.'))->status->toBe(ImportOutcome::SKIPPED)->words->toBe('A test account.')
        ->and(ImportOutcome::failed('No inbox.'))->status->toBe(ImportOutcome::FAILED)->words->toBe('No inbox.')
        ->and(fn () => ImportOutcome::failed(' '))->toThrow(InvalidArgumentException::class);
});

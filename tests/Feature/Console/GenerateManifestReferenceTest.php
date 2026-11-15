<?php

declare(strict_types=1);

/**
 * Top-level and nested property paths from a JSON Schema object, dotted.
 *
 * @return list<string>
 */
function manifestReferenceKeys(string $schemaPath): array
{
    $schema = json_decode((string) file_get_contents($schemaPath), true, 512, JSON_THROW_ON_ERROR);

    return manifestReferenceFlatten($schema);
}

/**
 * @param  array<string, mixed>  $node
 *
 * @return list<string>
 */
function manifestReferenceFlatten(array $node, string $prefix = ''): array
{
    $keys = [];
    $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];

    foreach ($properties as $name => $child) {
        $path = $prefix === '' ? (string) $name : $prefix.'.'.$name;
        $keys[] = $path;

        if (is_array($child)) {
            array_push($keys, ...manifestReferenceFlatten($child, $path));
        }
    }

    $items = $node['items'] ?? null;

    if (is_array($items)) {
        array_push($keys, ...manifestReferenceFlatten($items, $prefix === '' ? '[]' : $prefix.'[]'));
    }

    $additional = $node['additionalProperties'] ?? null;

    if (is_array($additional)) {
        array_push($keys, ...manifestReferenceFlatten($additional, $prefix === '' ? '*' : $prefix.'.*'));
    }

    return $keys;
}

it('writes every plugin manifest key once', function (): void {
    $path = storage_path('framework/testing/plugin-manifest.md');
    $keys = manifestReferenceKeys(base_path('packages/teme-contracts/schema/teme-plugin.schema.json'));

    $this->artisan('teme:docs:manifest', ['--output' => dirname($path)])->assertSuccessful();

    $markdown = (string) file_get_contents($path);

    expect($keys)->not->toBeEmpty();

    foreach ($keys as $key) {
        expect(substr_count($markdown, '`'.$key.'`'))->toBe(1, "expected one row for {$key}");
    }
});

it('writes every theme manifest key once', function (): void {
    $path = storage_path('framework/testing/theme-manifest.md');
    $keys = manifestReferenceKeys(base_path('packages/teme-contracts/schema/teme-theme.schema.json'));

    $this->artisan('teme:docs:manifest', ['--output' => dirname($path)])->assertSuccessful();

    $markdown = (string) file_get_contents($path);

    expect($keys)->not->toBeEmpty();

    foreach ($keys as $key) {
        expect(substr_count($markdown, '`'.$key.'`'))->toBe(1, "expected one row for {$key}");
    }
});

it('keeps the committed manifest references in step with the command', function (): void {
    $dir = storage_path('framework/testing/manifest-reference');

    $this->artisan('teme:docs:manifest', ['--output' => $dir])->assertSuccessful();

    expect(file_get_contents($dir.'/plugin-manifest.md'))->toBe(file_get_contents(base_path('docs/reference/plugin-manifest.md')))
        ->and(file_get_contents($dir.'/theme-manifest.md'))->toBe(file_get_contents(base_path('docs/reference/theme-manifest.md')));
});

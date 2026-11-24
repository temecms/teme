<?php

declare(strict_types=1);

use App\Domain\Regions\RegionDeclarations;
use App\Domain\Themes\ManifestValidator;
use Teme\Contracts\Settings\Setting;

/**
 * The schema as an array.
 *
 * @return array<string, mixed>
 */
function themeSchema(): array
{
    return json_decode((string) file_get_contents(base_path('packages/teme-contracts/schema/teme-theme.schema.json')), true, 512, JSON_THROW_ON_ERROR);
}

/** A regex constant's text between its delimiters, without its anchors. */
function regexBetweenDelimiters(string $regex): string
{
    return substr($regex, 1, -1);
}

/**
 * The default theme's manifest.
 *
 * @return array<string, mixed>
 */
function defaultManifest(): array
{
    return json_decode((string) file_get_contents(base_path('themes/default/teme-theme.json')), true, 512, JSON_THROW_ON_ERROR);
}

it('describes every key the validator reads, and no other but $schema, author and license', function (): void {
    $described = array_keys(themeSchema()['properties']);
    $expected = [...ManifestValidator::KEYS, '$schema', 'author', 'license'];
    sort($described);
    sort($expected);

    expect($described)->toBe($expected);
});

it('requires the six keys the validator refuses a manifest without', function (): void {
    $schema = themeSchema();

    expect($schema['required'])->toBe(['manifest', 'code', 'name', 'version', 'description', 'requires']);

    foreach ($schema['required'] as $key) {
        $manifest = defaultManifest();
        unset($manifest[$key]);

        expect(app(ManifestValidator::class)->errors($manifest, base_path('themes/default')))->not->toBe([], "without {$key}");
    }
});

it('states the validator’s patterns, word for word', function (): void {
    $p = themeSchema()['properties'];
    $slug = regexBetweenDelimiters(ManifestValidator::SLUG);

    expect($p['code']['pattern'])->toBe(regexBetweenDelimiters(ManifestValidator::CODE))
        ->and($p['templates']['additionalProperties']['propertyNames']['pattern'])->toBe($slug)
        ->and($p['navigations']['propertyNames']['pattern'])->toBe($slug)
        ->and($p['regions']['propertyNames']['pattern'])->toBe($slug)
        ->and($p['regions']['additionalProperties']['properties']['size']['pattern'])->toBe(regexBetweenDelimiters(RegionDeclarations::SIZE))
        ->and($p['settings']['items']['properties']['key']['pattern'])->toBe(regexBetweenDelimiters(ManifestValidator::SETTING_KEY))
        ->and($p['composers']['items']['pattern'])->toBe(regexBetweenDelimiters(ManifestValidator::CLASS_NAME));

    foreach (['tokens', 'tokens_dark'] as $group) {
        expect($p[$group]['properties']['base']['pattern'])->toBe(regexBetweenDelimiters(ManifestValidator::HEX))
            ->and($p[$group]['properties']['accent']['pattern'])->toBe(regexBetweenDelimiters(ManifestValidator::HEX));
    }
});

it('takes the same screenshots the validator takes, in any case', function (string $path, bool $valid): void {
    $pattern = themeSchema()['properties']['screenshot']['pattern'];

    expect(preg_match(ManifestValidator::SCREENSHOT, $path) === 1)->toBe($valid)
        ->and(preg_match('~'.$pattern.'~u', $path) === 1)->toBe($valid);
})->with([
    ['shot.png', true], ['img/shot.WEBP', true], ['a/b.Jpg', true], ['/shot.png', false], ['../shot.png', false],
    ['a/../shot.png', false], ['a\\shot.png', false], ['c:shot.png', false], ['shot.gif', false], ['my shot.png', false],
]);

it('lists the same tokens, setting types, fieldsets and font styles', function (): void {
    $p = themeSchema()['properties'];

    expect($p['tokens']['propertyNames']['enum'])->toBe(ManifestValidator::TOKENS)
        ->and($p['tokens_dark']['propertyNames']['enum'])->toBe(ManifestValidator::TOKENS)
        ->and($p['settings']['items']['properties']['type']['enum'])->toBe(ManifestValidator::SETTING_TYPES)
        ->and($p['settings']['items']['properties']['fieldset']['enum'])->toBe(Setting::FIELDSETS)
        ->and($p['fonts']['items']['properties']['style']['enum'])->toBe(['normal', 'italic', 'oblique']);
});

it('states the same bounds', function (): void {
    $p = themeSchema()['properties'];
    $max = $p['regions']['additionalProperties']['properties']['max'];

    expect($p['per_page']['minimum'])->toBe(1)
        ->and($p['per_page']['maximum'])->toBe(100)
        ->and($max['minimum'])->toBe(1)
        ->and($max['maximum'])->toBe(50)
        ->and($p['title_separator']['minLength'])->toBe(1)
        ->and($p['title_separator']['maxLength'])->toBe(3)
        ->and($p['manifest']['const'])->toBe(1);
});

it('accepts the default theme and refuses a parent', function (): void {
    $schema = themeSchema();
    $outside = array_diff(array_keys(defaultManifest()), array_keys($schema['properties']));
    $named = array_merge(...array_map(fn (array $rule): array => $rule['required'], $schema['not']['anyOf']));

    expect($outside)->toBe([])
        ->and(array_intersect(array_keys(defaultManifest()), $named))->toBe([])
        ->and($named)->toBe(['parent', 'extends']);
});

it('judges whole manifests as the validator does, and says so in the schema path that decides it', function (callable $change, bool $accepted, callable $statement): void {
    $manifest = $change(defaultManifest());
    $errors = app(ManifestValidator::class)->errors($manifest, base_path('themes/default'));

    expect($errors === [])->toBe($accepted, implode(' ', $errors));
    expect($statement(themeSchema()))->toBeTrue();
})->with([
    'assets without a manifest, refused' => [
        fn (array $m): array => [...$m, 'assets' => ['entries' => []]],
        false,
        fn (array $s): bool => in_array('manifest', $s['properties']['assets']['required'] ?? [], true),
    ],
    'layout without content, refused' => [
        fn (array $m): array => [...$m, 'layout' => ['wide' => 1152, 'full' => null]],
        false,
        fn (array $s): bool => in_array('content', $s['properties']['layout']['required'] ?? [], true),
    ],
    'layout without wide, refused' => [
        fn (array $m): array => [...$m, 'layout' => ['content' => 680, 'full' => null]],
        false,
        fn (array $s): bool => in_array('wide', $s['properties']['layout']['required'] ?? [], true),
    ],
    'a font weight as the integer 400, accepted' => [
        function (array $m): array {
            $m['fonts'][0]['weight'] = 400;

            return $m;
        },
        true,
        fn (array $s): bool => in_array('integer', (array) $s['properties']['fonts']['items']['properties']['weight']['type'], true),
    ],
    'a font weight as the string "400", accepted' => [
        function (array $m): array {
            $m['fonts'][0]['weight'] = '400';

            return $m;
        },
        true,
        fn (array $s): bool => in_array('string', (array) $s['properties']['fonts']['items']['properties']['weight']['type'], true),
    ],
    'a composers entry that is not a class name, refused' => [
        fn (array $m): array => [...$m, 'composers' => ['not a class!']],
        false,
        fn (array $s): bool => preg_match('~'.$s['properties']['composers']['items']['pattern'].'~', 'not a class!') === 0,
    ],
    'a composers entry that is a class name, accepted' => [
        fn (array $m): array => [...$m, 'composers' => ['\\App\\Composers\\Menu']],
        true,
        fn (array $s): bool => preg_match('~'.$s['properties']['composers']['items']['pattern'].'~', '\\App\\Composers\\Menu') === 1,
    ],
    'a preload that is not a boolean, accepted' => [
        function (array $m): array {
            $m['fonts'][0]['preload'] = 'yes';

            return $m;
        },
        true,
        fn (array $s): bool => ! array_key_exists('type', $s['properties']['fonts']['items']['properties']['preload']),
    ],
    'a name of spaces only, refused' => [
        fn (array $m): array => [...$m, 'name' => '   '],
        false,
        fn (array $s): bool => preg_match('~'.$s['properties']['name']['pattern'].'~', '   ') === 0,
    ],
]);

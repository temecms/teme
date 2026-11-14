<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\ContributionsValidator;
use Tests\Fixtures\Plugins\Lists\SchemaCheck;

/*
 * The schema and the validator give the same verdict on a plugin's
 * `contributes`, the stricter one where they differed, so an author whose
 * editor checks the schema meets no surprise at install.
 */
it('gives the schema’s verdict and the validator’s alike', function (string $contributes, bool $usable): void {
    $schema = json_decode((string) file_get_contents(base_path('packages/teme-contracts/schema/teme-plugin.schema.json')), flags: JSON_THROW_ON_ERROR);

    expect(SchemaCheck::passes(json_decode($contributes, flags: JSON_THROW_ON_ERROR), $schema->properties->contributes))->toBe($usable)
        ->and(app(ContributionsValidator::class)->errors(json_decode($contributes, true, flags: JSON_THROW_ON_ERROR), '1.0.0') === [])->toBe($usable);
})->with([
    'settings and links' => ['{"settings":[{"key":"token","type":"secret","label":"Token","description":"The token."}],"admin":[{"surface":"tools","id":"open","label":"Open Sync","description":"Your workspace.","icon":"external-link","to":"https://sync.example"}]}', true],
    'a link without a description' => ['{"admin":[{"surface":"tools","id":"open","label":"Open Sync","to":"settings"}]}', true],
    'a setting without a label' => ['{"settings":[{"key":"token","type":"secret","description":"The token."}]}', false],
    'a setting without a description' => ['{"settings":[{"key":"token","type":"secret","label":"Token"}]}', false],
    'a setting whose label isn’t text' => ['{"settings":[{"key":"token","type":"secret","label":7,"description":"The token."}]}', false],
    'a link whose description isn’t text' => ['{"admin":[{"surface":"tools","id":"open","label":"Open","description":5,"to":"settings"}]}', false],
    'a link with an empty label' => ['{"admin":[{"surface":"tools","id":"open","label":"","to":"settings"}]}', false],
    'a key with a capital' => ['{"settings":[{"key":"Token","type":"text","label":"Token","description":"The token."}]}', false],
    'an unknown type' => ['{"settings":[{"key":"when","type":"date","label":"When","description":"The day."}]}', false],
    'an unknown surface' => ['{"admin":[{"surface":"editor.pane","id":"open","label":"Open","to":"settings"}]}', false],
    'an unknown icon' => ['{"admin":[{"surface":"tools","id":"open","label":"Open","icon":"skull","to":"settings"}]}', false],
    'plain http' => ['{"admin":[{"surface":"tools","id":"open","label":"Open","to":"http://sync.example"}]}', false],
    'something Teme doesn’t offer' => ['{"widgets":[]}', false],
]);

it('says why, in its own words, for each refusal the schema already made', function (array $contributes, string $words): void {
    expect(app(ContributionsValidator::class)->errors($contributes, '1.0.0'))->toBe([$words]);
})->with([
    'no label' => [['settings' => [['key' => 'token', 'type' => 'secret', 'description' => 'The token.']]], 'A setting needs a label.'],
    'no description' => [['settings' => [['key' => 'token', 'type' => 'secret', 'label' => 'Token']]], 'A setting needs a description.'],
    'a description that isn’t text' => [['admin' => [['surface' => 'tools', 'id' => 'open', 'label' => 'Open', 'description' => 5, 'to' => 'settings']]], 'A link’s description must be text.'],
]);

it('refuses two links with one id from one plugin', function (): void {
    $link = ['surface' => 'tools', 'id' => 'open', 'label' => 'Open', 'to' => 'settings'];

    expect(app(ContributionsValidator::class)->errors(['admin' => [$link, [...$link, 'label' => 'Open again']]], '1.0.0'))
        ->toBe(['Two links can’t share the id open.']);
});

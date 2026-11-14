<?php

declare(strict_types=1);

use App\Domain\Plugins\HostPattern;
use App\Domain\Plugins\ManifestValidator;
use App\Domain\Themes\ManifestValidator as ThemeManifestValidator;
use Teme\Contracts\Plugins\Capability;
use Tests\Fixtures\Plugins\PluginFixtures;

beforeEach(function (): void {
    config(['teme.version' => PluginFixtures::TEME]);
    $this->schema = json_decode((string) file_get_contents(base_path('packages/teme-contracts/schema/teme-plugin.schema.json')), true, flags: JSON_THROW_ON_ERROR);
    $this->between = fn (string $pattern): string => substr($pattern, 1, strrpos($pattern, $pattern[0]) - 1);
});

it('requires the keys the validator refuses a manifest without', function (): void {
    $keys = ['manifest', 'name', 'code', 'label', 'description', 'version', 'requires', 'plugin'];

    expect($this->schema['required'])->toBe($keys);

    foreach ($keys as $key) {
        $manifest = PluginFixtures::manifest('hello');
        unset($manifest[$key]);

        expect(app(ManifestValidator::class)->errors($manifest, PluginFixtures::path('hello'), null))->not->toBe([], "without {$key}");
    }
});

it('states the validator’s patterns and lists, word for word', function (): void {
    $properties = $this->schema['properties'];

    expect($properties['code']['pattern'])->toBe('^'.ThemeManifestValidator::CODE_PATTERN.'$')
        ->and($properties['capabilities']['items']['enum'])->toBe(array_column(Capability::cases(), 'value'))
        ->and($properties['hosts']['items']['pattern'])->toBe(($this->between)(HostPattern::PATTERN))
        ->and($properties['version']['pattern'])->toBe(($this->between)(ManifestValidator::VERSION))
        ->and($properties['name']['pattern'])->toBe(($this->between)(ManifestValidator::NAME));
});

it('takes the same versions the validator takes', function (string $version, bool $ok): void {
    $schema = '/'.$this->schema['properties']['version']['pattern'].'/';
    $manifest = [...PluginFixtures::manifest('hello'), 'version' => $version];
    $errors = app(ManifestValidator::class)->errors($manifest, PluginFixtures::path('hello'), null);

    expect(preg_match($schema, $version) === 1)->toBe($ok)
        ->and(in_array('version must be a version number, such as 1.2.0.', $errors, true))->toBe(! $ok);
})->with([['1.2.0', true], ['1.2.0-beta.3', true], ['1.2', false], ['v1.2.0', false], ['1.2.0-dev', false]]);

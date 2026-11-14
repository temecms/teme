<?php

declare(strict_types=1);

use App\Domain\Updates\TemeVersion;
use Composer\Semver\Semver;

it('takes a release’s version from the environment, and the untagged version for anything else', function (mixed $value, string $version, bool $tagged): void {
    expect(TemeVersion::fromEnvironment($value))->toBe($version)
        ->and(TemeVersion::isRelease((string) $value))->toBe($tagged);
})->with([
    'a release' => ['0.1.0', '0.1.0', true],
    'a pre-release' => ['0.1.0-rc.14', '0.1.0-rc.14', true],
    'an alpha' => ['0.2.0-alpha.1', '0.2.0-alpha.1', true],
    'unset' => [null, TemeVersion::UNTAGGED, false],
    'empty' => ['', TemeVersion::UNTAGGED, false],
    'a leading v' => ['v0.1.0', TemeVersion::UNTAGGED, false],
    'a commit tag' => ['sha-1a2b3c4d5e6f', TemeVersion::UNTAGGED, false],
    'an unknown label' => ['0.1.0-preview.1', TemeVersion::UNTAGGED, false],
    'a leading zero' => ['0.01.0', TemeVersion::UNTAGGED, false],
    'a word' => ['latest', TemeVersion::UNTAGGED, false],
]);

it('orders versions as the roadmap’s scheme does: alpha, beta, rc, then the release', function (): void {
    $versions = ['0.2.0-alpha.1', '0.1.0', '0.1.0-rc.13', '0.1.1', '0.1.0-rc.2', '0.1.0-beta.10', '0.1.0-alpha.5', '1.0.0'];

    usort($versions, TemeVersion::compare(...));

    expect($versions)->toBe(['0.1.0-alpha.5', '0.1.0-beta.10', '0.1.0-rc.2', '0.1.0-rc.13', '0.1.0', '0.1.1', '0.2.0-alpha.1', '1.0.0'])
        ->and(TemeVersion::compare('0.1.0-rc.14', '0.1.0-rc.14'))->toBe(0)
        ->and(TemeVersion::isPrerelease('0.1.0-rc.14'))->toBeTrue()
        ->and(TemeVersion::isPrerelease('0.1.0'))->toBeFalse();
});

it('keeps a revision only when it is a whole commit', function (mixed $value, ?string $revision): void {
    expect(TemeVersion::revision($value))->toBe($revision);
})->with([
    'a commit' => ['102fb4f937d05d3bce9744d0f3bb00c590c45a7f', '102fb4f937d05d3bce9744d0f3bb00c590c45a7f'],
    'upper case' => ['102FB4F937D05D3BCE9744D0F3BB00C590C45A7F', null],
    'short' => ['102fb4f9', null],
    'unset' => [null, null],
]);

it('reads the running version and whether it is a release from the configuration', function (): void {
    config(['teme.version' => '0.1.0-rc.14', 'teme.tagged' => true]);

    expect(TemeVersion::current())->toBe('0.1.0-rc.14')
        ->and(TemeVersion::tagged())->toBeTrue();
});

it('checks themes against the untagged version, which every theme’s ^0.1 accepts', function (): void {
    expect(TemeVersion::UNTAGGED)->toBe('0.1.0')
        ->and(Semver::satisfies(TemeVersion::UNTAGGED, '^0.1'))->toBeTrue()
        ->and(Semver::satisfies('0.1.0-rc.14', '^0.1'))->toBeTrue();
});

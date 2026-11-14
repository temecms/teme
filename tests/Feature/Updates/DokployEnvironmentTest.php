<?php

declare(strict_types=1);

use App\Domain\Updates\Updater\DokployEnvironment;
use App\Domain\Updates\Updater\ImageSetting;
use App\Domain\Updates\Updater\PlatformRefused;

const DE_OLD = 'ghcr.io/temecms/teme:0.1.0-rc.14';
const DE_NEW = 'ghcr.io/temecms/teme:0.1.0-rc.15';

/** The text of a Compose service's environment, with a planted APP_KEY, a quoted password and the token around $line. */
function deText(string $lines, string $eol = "\n"): string
{
    return implode($eol, ['APP_KEY=base64:abc$def', $lines, 'DB_PASSWORD="p#ss word"', 'TEME_DOKPLOY_TOKEN=tok']).$eol;
}

it('changes only the value of the one line that sets the image', function (string $line, string $eol = "\n"): void {
    $before = deText($line, $eol);
    $changed = DokployEnvironment::with($before, ImageSetting::Site, DE_NEW);

    expect($changed)->toBe(deText(str_replace(DE_OLD, DE_NEW, $line), $eol))
        ->and(DokployEnvironment::image($before, ImageSetting::Site))->toBe(DE_OLD)
        ->and(DokployEnvironment::image($changed, ImageSetting::Site))->toBe(DE_NEW);
})->with([
    'plain' => ['TEME_IMAGE='.DE_OLD],
    'CRLF endings' => ['TEME_IMAGE='.DE_OLD, "\r\n"],
    'double quotes' => ['TEME_IMAGE="'.DE_OLD.'"'],
    'single quotes' => ["TEME_IMAGE='".DE_OLD."'"],
    'export' => ['export TEME_IMAGE='.DE_OLD],
    'spaces around =' => ['TEME_IMAGE = '.DE_OLD],
    'a colon' => ['TEME_IMAGE: '.DE_OLD],
    'trailing spaces' => ['TEME_IMAGE='.DE_OLD.'  '],
]);

it('changes the line wherever it stands, with or without a final newline', function (): void {
    $first = 'TEME_IMAGE='.DE_OLD."\nAPP_KEY=base64:abc\$def\n";
    $last = "APP_KEY=base64:abc\$def\nTEME_IMAGE=".DE_OLD;

    expect(DokployEnvironment::with($first, ImageSetting::Site, DE_NEW))->toBe(str_replace(DE_OLD, DE_NEW, $first))
        ->and(DokployEnvironment::with($last, ImageSetting::Site, DE_NEW))->toBe(str_replace(DE_OLD, DE_NEW, $last));
});

it('leaves the updater’s setting untouched when the site’s is set, and the reverse', function (): void {
    $text = deText('TEME_IMAGE='.DE_OLD."\nTEME_UPDATER_IMAGE=".DE_OLD);

    expect(DokployEnvironment::with($text, ImageSetting::Site, DE_NEW))->toBe(deText('TEME_IMAGE='.DE_NEW."\nTEME_UPDATER_IMAGE=".DE_OLD))
        ->and(DokployEnvironment::with($text, ImageSetting::Updater, DE_NEW))->toBe(deText('TEME_IMAGE='.DE_OLD."\nTEME_UPDATER_IMAGE=".DE_NEW));
});

it('refuses a text where the image isn’t set exactly once to a plain value', function (string $text): void {
    expect(DokployEnvironment::image($text, ImageSetting::Site))->toBeNull();

    try {
        DokployEnvironment::with($text, ImageSetting::Site, DE_NEW);
        $this->fail('Expected a refusal.');
    } catch (PlatformRefused $refused) {
        expect($refused->getMessage())->toBe('TEME_IMAGE must be set once, to an image name, on the Compose service’s Environment tab.')
            ->and($refused->getMessage())->not->toContain('base64')
            ->and($refused->getMessage())->not->toContain('tok');
    }
})->with([
    'none' => [deText('OTHER=1')],
    'twice' => [deText('TEME_IMAGE='.DE_OLD."\nTEME_IMAGE=".DE_OLD)],
    'a reference' => [deText('TEME_IMAGE=${{environment.TEME_IMAGE}}')],
    'a comment after it' => [deText('TEME_IMAGE='.DE_OLD.' # pinned')],
    'empty' => [deText('TEME_IMAGE=')],
    'a space inside' => [deText('TEME_IMAGE=ghcr.io/x/teme: 0.1.0')],
]);

it('never takes a line that only mentions the name', function (): void {
    $text = deText("OLD_TEME_IMAGE=ghcr.io/x/teme:1.0.0\nNOTE=TEME_IMAGE=ghcr.io/x/teme:2.0.0\n# TEME_IMAGE=ghcr.io/x/teme:3.0.0\nTEME_IMAGE=".DE_OLD);

    expect(DokployEnvironment::image($text, ImageSetting::Site))->toBe(DE_OLD)
        ->and(DokployEnvironment::with($text, ImageSetting::Site, DE_NEW))->toBe(str_replace('TEME_IMAGE='.DE_OLD, 'TEME_IMAGE='.DE_NEW, $text));
});

it('reads NAME: as dotenv does', function (): void {
    expect(DokployEnvironment::image('TEME_IMAGE: '.DE_OLD."\n", ImageSetting::Site))->toBe(DE_OLD)
        ->and(DokployEnvironment::with('TEME_IMAGE: '.DE_OLD."\n", ImageSetting::Site, DE_NEW))->toBe('TEME_IMAGE: '.DE_NEW."\n")
        ->and(DokployEnvironment::image('TEME_IMAGE: '.DE_OLD."\nTEME_IMAGE=".DE_OLD."\n", ImageSetting::Site))->toBeNull();
});

it('ignores lines inside a multi-line quoted value', function (): void {
    $key = "PRIVATE_KEY=\"-----BEGIN KEY-----\nTEME_IMAGE=ghcr.io/evil/teme:1.0.0\nabc\n-----END KEY-----\"";
    $text = $key."\nTEME_IMAGE=".DE_OLD."\nAPP_KEY=base64:abc\$def\n";

    expect(DokployEnvironment::image($text, ImageSetting::Site))->toBe(DE_OLD)
        ->and(DokployEnvironment::with($text, ImageSetting::Site, DE_NEW))->toBe($key."\nTEME_IMAGE=".DE_NEW."\nAPP_KEY=base64:abc\$def\n");
});

it('refuses an image Teme doesn’t build', function (string $image): void {
    $text = deText('TEME_IMAGE='.DE_OLD);

    expect(fn () => DokployEnvironment::with($text, ImageSetting::Site, $image))->toThrow(PlatformRefused::class, 'Teme couldn’t change TEME_IMAGE alone, so it changed nothing.');
})->with([
    'a command' => ['ghcr.io/x/teme:0.1.0; rm -rf /'],
    'a newline' => ["ghcr.io/x/teme:0.1.0\nOTHER=1"],
    'a trailing newline' => ["ghcr.io/x/teme:0.1.0\n"],
    'upper case' => ['GHCR.IO/x/teme:0.1.0'],
]);

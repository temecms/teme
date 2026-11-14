<?php

declare(strict_types=1);

namespace App\Domain\Updates\Updater;

use App\Domain\Updates\ImageName;
use SensitiveParameter;

/**
 * Reads and changes one image setting in a Dokploy Compose service's environment (I-3333). Dokploy keeps the
 * whole environment as one text and replaces it whole, so only the value on the one line that sets the
 * image is changed, and every other byte is kept.
 */
final class DokployEnvironment
{
    /**
     * Returns the setting's image, or null when no line or more than one sets it, or its value isn't plain.
     */
    public static function image(#[SensitiveParameter] string $environment, ImageSetting $setting): ?string
    {
        return self::find($environment, $setting)['value'] ?? null;
    }

    /**
     * Returns the environment with that setting's value replaced by the image, and nothing else changed.
     *
     * @throws PlatformRefused
     */
    public static function with(#[SensitiveParameter] string $environment, ImageSetting $setting, string $image): string
    {
        if (preg_match(ImageName::IMAGE, $image) !== 1) {
            throw new PlatformRefused('Teme couldn’t change '.$setting->value.' alone, so it changed nothing.');
        }

        $found = self::find($environment, $setting)
            ?? throw new PlatformRefused($setting->value.' must be set once, to an image name, on the Compose service’s Environment tab.');

        $changed = substr_replace($environment, $image, $found['offset'], strlen($found['value']));
        $after = $found['offset'] + strlen($found['value']);

        if (strlen($changed) !== strlen($environment) - strlen($found['value']) + strlen($image)
            || substr($changed, 0, $found['offset']) !== substr($environment, 0, $found['offset'])
            || substr($changed, $found['offset'] + strlen($image)) !== substr($environment, $after)) {
            throw new PlatformRefused('Teme couldn’t change '.$setting->value.' alone, so it changed nothing.');
        }

        return $changed;
    }

    /**
     * @return array{value: string, offset: int}|null
     */
    private static function find(#[SensitiveParameter] string $environment, ImageSetting $setting): ?array
    {
        $name = preg_quote($setting->value, '/');
        // dotenv takes NAME=value and NAME: value alike.
        $sets = '/^[ \t]*(?:export[ \t]+)?'.$name.'[ \t]*[=:]/';
        $plain = '/^[ \t]*(?:export[ \t]+)?'.$name.'[ \t]*[=:][ \t]*(["\']?)([A-Za-z0-9.\/:+-]+)\1[ \t]*\r?$/D';
        $opens = '/^[ \t]*(?:export[ \t]+)?[A-Za-z_][A-Za-z0-9_.-]*[ \t]*[=:][ \t]*(["\'`])/';
        $count = 0;
        $found = null;
        $open = null;     // the quote a multi-line value opened, while the walk is inside it
        $offset = 0;

        foreach (preg_split('/(?<=\n)/', $environment) ?: [] as $line) {
            $text = rtrim($line, "\n");

            if ($open !== null) {
                // Inside a value that spans lines (a key, a certificate): nothing here sets a name.
                if (preg_match('/(?<!\\\\)'.preg_quote($open, '/').'/', $text) === 1) {
                    $open = null;
                }
            } else {
                if (preg_match($sets, $text) === 1) {
                    $count++;
                    $found = preg_match($plain, $text, $match, PREG_OFFSET_CAPTURE) === 1
                        ? ['value' => $match[2][0], 'offset' => $offset + $match[2][1]]
                        : null;
                }

                // A value that opens a quote and doesn't close it on this line runs on to a later one.
                if (preg_match($opens, $text, $quote, PREG_OFFSET_CAPTURE) === 1
                    && preg_match('/(?<!\\\\)'.preg_quote($quote[1][0], '/').'/', substr($text, $quote[1][1] + 1)) !== 1) {
                    $open = $quote[1][0];
                }
            }

            $offset += strlen($line);
        }

        // None, or two (dotenv would take the last; Teme takes neither).
        return $count === 1 ? $found : null;
    }
}

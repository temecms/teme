<?php

/**
 * What a plugin's manifest declares for its stylesheets under contributes.styles
 * (docs/guides/developers/plugins/styles.md): one built CSS file per output, where
 * an output is a card or a page insertion. Checks each entry's shape, so a bad
 * declaration is refused at enable rather than linked on a page.
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Styles;

final class DeclaredStyles
{
    /** An output: `card:<id>` or `insertion:<id>`. */
    private const OUTPUT = '/^(card:[a-z][a-z0-9-]{1,40}|insertion:[a-z][a-z0-9-]{1,40})$/';

    /** Most sheets a plugin may declare. */
    private const MOST = 40;

    /**
     * The problems with what a plugin declared under contributes.styles, or
     * none. A file is a relative path to a .css file inside the package, and each
     * output has at most one sheet; a card output must name a card the plugin declares.
     *
     * @param  array<array-key, mixed>  $contributes
     *
     * @return list<string>
     */
    public static function errors(array $contributes): array
    {
        if (! array_key_exists('styles', $contributes)) {
            return [];
        }

        $styles = $contributes['styles'];

        if (! is_array($styles) || ! array_is_list($styles)) {
            return [__('styles must list the plugin’s stylesheets.')];
        }

        if (count($styles) > self::MOST) {
            return [__('A plugin can declare at most 40 stylesheets.')];
        }

        $errors = [];
        $outputs = [];
        $cards = self::cardIds($contributes);

        foreach ($styles as $style) {
            $style = is_array($style) ? $style : [];
            $output = is_string($style['output'] ?? null) ? $style['output'] : '';
            $file = is_string($style['file'] ?? null) ? $style['file'] : '';

            if (preg_match(self::OUTPUT, $output) !== 1) {
                $errors[] = __('A stylesheet’s output must be card:<id> or insertion:<id>.');
            } elseif (str_starts_with($output, 'card:') && ! in_array(substr($output, 5), $cards, true)) {
                $errors[] = __('A stylesheet names a card the plugin doesn’t declare.');
            }

            if (in_array($output, $outputs, true)) {
                $errors[] = __('Each output can have one stylesheet.');
            }

            $outputs[] = $output;

            if (! self::isPackageCss($file)) {
                $errors[] = __('A stylesheet must be a .css file inside the plugin’s package.');
            }
        }

        return $errors;
    }

    /**
     * The declared stylesheets, each as its output and its file, from what
     * the manifest's contributes gives. Entries that don't check are skipped.
     *
     * @param  array<array-key, mixed>  $contributes
     *
     * @return list<array{output: string, file: string}>
     */
    public static function entries(array $contributes): array
    {
        $styles = is_array($contributes['styles'] ?? null) ? $contributes['styles'] : [];
        $entries = [];

        foreach ($styles as $style) {
            $style = is_array($style) ? $style : [];
            $output = is_string($style['output'] ?? null) ? $style['output'] : '';
            $file = is_string($style['file'] ?? null) ? $style['file'] : '';

            if (preg_match(self::OUTPUT, $output) === 1 && self::isPackageCss($file)) {
                $entries[] = ['output' => $output, 'file' => $file];
            }
        }

        return $entries;
    }

    /**
     * The ids of the cards the plugin declares, so a card's sheet can name one that exists.
     *
     * @param  array<array-key, mixed>  $contributes
     *
     * @return list<string>
     */
    private static function cardIds(array $contributes): array
    {
        $cards = is_array($contributes['cards'] ?? null) ? $contributes['cards'] : [];

        return array_values(array_filter(array_map(
            fn (mixed $card): ?string => is_array($card) && is_string($card['id'] ?? null) ? $card['id'] : null,
            $cards,
        ), fn (?string $id): bool => $id !== null));
    }

    /**
     * Whether a path is a relative .css path with no parent segments, no leading slash
     * and no backslashes.
     */
    private static function isPackageCss(string $file): bool
    {
        return preg_match('/\.css\z/', $file) === 1
            && ! str_starts_with($file, '/')
            && ! str_contains($file, '\\')
            && ! in_array('..', explode('/', $file), true);
    }
}

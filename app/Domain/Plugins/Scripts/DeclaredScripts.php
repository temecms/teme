<?php

/**
 * What a plugin's manifest declares for its scripts under contributes.scripts
 * (docs/guides/developers/plugins/scripts.md): one built JavaScript file per output, where
 * an output is a card or a page insertion. Checks each entry's shape and the capability
 * that lets it run, so a bad declaration is refused at enable rather than linked on a page.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Scripts;

use Teme\Contracts\Plugins\Capability;

final class DeclaredScripts
{
    /** An output: `card:<id>` or `insertion:<id>`. */
    private const string OUTPUT = '/^(card:[a-z][a-z0-9-]{1,40}|insertion:[a-z][a-z0-9-]{1,40})$/';

    /** Most scripts a plugin may declare. */
    private const int MOST = 40;

    /**
     * The problems with what a plugin declared under contributes.scripts, or none. A file is a
     * relative path to a .js file inside the package, each output has at most one script, a card
     * output names a card the plugin declares, and the plugin must ask for site.scripts.
     *
     * @param  array<array-key, mixed>  $contributes
     * @param  list<string>  $capabilities
     *
     * @return list<string>
     */
    public static function errors(array $contributes, array $capabilities): array
    {
        if (! array_key_exists('scripts', $contributes)) {
            return [];
        }

        $scripts = $contributes['scripts'];

        if (! is_array($scripts) || ! array_is_list($scripts)) {
            return [__('scripts must list the plugin’s scripts.')];
        }

        if (count($scripts) > self::MOST) {
            return [__('A plugin can declare at most 40 scripts.')];
        }

        if ($scripts !== [] && ! in_array(Capability::SiteScripts->value, $capabilities, true)) {
            return [__('The plugin declares scripts, but it doesn’t ask for :capability.', ['capability' => Capability::SiteScripts->value])];
        }

        $errors = [];
        $outputs = [];
        $cards = self::cardIds($contributes);

        foreach ($scripts as $script) {
            $script = is_array($script) ? $script : [];
            $output = is_string($script['output'] ?? null) ? $script['output'] : '';
            $file = is_string($script['file'] ?? null) ? $script['file'] : '';

            if (preg_match(self::OUTPUT, $output) !== 1) {
                $errors[] = __('A script’s output must be card:<id> or insertion:<id>.');
            } elseif (str_starts_with($output, 'card:') && ! in_array(substr($output, 5), $cards, true)) {
                $errors[] = __('A script names a card the plugin doesn’t declare.');
            }

            if (in_array($output, $outputs, true)) {
                $errors[] = __('Each output can have one script.');
            }

            $outputs[] = $output;

            if (! self::isPackageJs($file)) {
                $errors[] = __('A script must be a .js file inside the plugin’s package.');
            }
        }

        return $errors;
    }

    /**
     * The declared scripts, each as its output and its file, from what the manifest's contributes
     * gives. Entries that don't check are skipped.
     *
     * @param  array<array-key, mixed>  $contributes
     *
     * @return list<array{output: string, file: string}>
     */
    public static function entries(array $contributes): array
    {
        $scripts = is_array($contributes['scripts'] ?? null) ? $contributes['scripts'] : [];
        $entries = [];

        foreach ($scripts as $script) {
            $script = is_array($script) ? $script : [];
            $output = is_string($script['output'] ?? null) ? $script['output'] : '';
            $file = is_string($script['file'] ?? null) ? $script['file'] : '';

            if (preg_match(self::OUTPUT, $output) === 1 && self::isPackageJs($file)) {
                $entries[] = ['output' => $output, 'file' => $file];
            }
        }

        return $entries;
    }

    /**
     * The ids of the cards the plugin declares, so a card's script can name one that exists.
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
     * Whether a path is a relative .js path with no parent segments, no leading slash and no backslashes.
     */
    private static function isPackageJs(string $file): bool
    {
        return preg_match('/\.js\z/', $file) === 1
            && ! str_starts_with($file, '/')
            && ! str_contains($file, '\\')
            && ! in_array('..', explode('/', $file), true);
    }
}

<?php

/**
 * A plugin's declared console commands as the manifest states them, and how
 * each is checked: its name starts with the plugin's code and a colon, its
 * arguments and options are named, and the plugin can answer for it
 * (docs/guides/developers/plugins/commands.md).
 *
 * @package Teme
 * @since   1.7.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Console;

use App\Domain\Plugins\Manifest;
use Teme\Contracts\Console\HasCommands;

/**
 * @phpstan-type ArgumentDeclaration array{name: string, description: string, required: bool}
 * @phpstan-type OptionDeclaration array{name: string, description: string, value: bool}
 * @phpstan-type CommandDeclaration array{name: string, description: string, arguments: list<ArgumentDeclaration>, options: list<OptionDeclaration>}
 */
final class DeclaredCommands
{
    /** A command's name: the plugin's code, a colon, and words of lower-case letters, digits and hyphens, with more colons for groups. */
    public const string NAME = '/^[a-z0-9][a-z0-9-]{1,39}:[a-z][a-z0-9-]{0,31}(?::[a-z][a-z0-9-]{0,31}){0,2}$/D';

    /** An argument's or an option's name. */
    public const string PART = '/^[a-z][a-z0-9-]{0,29}$/D';

    /** At most this many commands per plugin. */
    public const int MOST = 10;

    /** At most this many arguments, and this many options, per command. */
    public const int MOST_PARTS = 5;

    /** The options every command already has, which a plugin can't take. */
    public const array RESERVED = ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction', 'env'];

    /**
     * The commands this manifest declares, defaults filled; those that are
     * not well formed are left out, the validator having refused them first.
     *
     * @return list<CommandDeclaration>
     */
    public static function of(Manifest $manifest): array
    {
        return self::read($manifest->contributes()['commands'] ?? []);
    }

    /**
     * The commands in this `contributes.commands` value, defaults filled.
     *
     * @return list<CommandDeclaration>
     */
    public static function read(mixed $commands): array
    {
        $read = [];

        foreach (is_array($commands) ? $commands : [] as $entry) {
            if (! is_array($entry) || ! is_string($entry['name'] ?? null) || ! is_string($entry['description'] ?? null)) {
                continue;
            }

            $arguments = [];
            $options = [];

            foreach (is_array($entry['arguments'] ?? null) ? $entry['arguments'] : [] as $argument) {
                if (is_array($argument) && is_string($argument['name'] ?? null)) {
                    $arguments[] = ['name' => $argument['name'], 'description' => is_string($argument['description'] ?? null) ? $argument['description'] : '', 'required' => ($argument['required'] ?? false) === true];
                }
            }

            foreach (is_array($entry['options'] ?? null) ? $entry['options'] : [] as $option) {
                if (is_array($option) && is_string($option['name'] ?? null)) {
                    $options[] = ['name' => $option['name'], 'description' => is_string($option['description'] ?? null) ? $option['description'] : '', 'value' => ($option['value'] ?? false) === true];
                }
            }

            $read[] = ['name' => $entry['name'], 'description' => $entry['description'], 'arguments' => $arguments, 'options' => $options];
        }

        return $read;
    }

    /**
     * Why these declarations can't be used, or nothing when they can: the
     * shape of each, its name's form, and that no name is used twice.
     *
     * @return list<string>
     */
    public static function errors(mixed $commands): array
    {
        if (! is_array($commands) || ! array_is_list($commands)) {
            return [__('commands must be a list of command declarations.')];
        }

        if (count($commands) > self::MOST) {
            return [__('A plugin may declare at most :most commands.', ['most' => self::MOST])];
        }

        $errors = [];
        $seen = [];

        foreach ($commands as $entry) {
            $name = is_array($entry) && is_string($entry['name'] ?? null) ? $entry['name'] : null;

            if (! is_array($entry) || array_diff(array_keys($entry), ['name', 'description', 'arguments', 'options']) !== []) {
                $errors[] = __('Each command is an object of a name, a description, and optionally arguments and options.');

                continue;
            }

            if ($name === null || preg_match(self::NAME, $name) !== 1) {
                $errors[] = __('A command’s name is its plugin’s code, a colon and lower-case words, such as sharely:resend.');
            } elseif (isset($seen[$name])) {
                $errors[] = __('The command :name is declared more than once.', ['name' => $name]);
            }

            if ($name !== null) {
                $seen[$name] = true;
            }

            $description = $entry['description'] ?? null;

            if (! is_string($description) || trim($description) === '' || mb_strlen($description) > 120 || preg_match('/[\r\n]/', $description) === 1) {
                $errors[] = __('The command :name needs a one-line description of 1 to 120 characters.', ['name' => (string) $name]);
            }

            array_push($errors, ...self::partErrors((string) $name, 'arguments', $entry['arguments'] ?? []));
            array_push($errors, ...self::partErrors((string) $name, 'options', $entry['options'] ?? []));
        }

        return $errors;
    }

    /**
     * Why a name can't be used by the plugin with this code: it doesn't start
     * with the code and a colon, or it is in Teme's own `teme:` space.
     *
     * @param  array<array-key, mixed>  $contributes  the manifest's `contributes`
     *
     * @return list<string>
     */
    public static function prefixErrors(string $code, array $contributes): array
    {
        $errors = [];

        foreach (self::read($contributes['commands'] ?? []) as $command) {
            if ($code === 'teme' || ! str_starts_with($command['name'], $code.':')) {
                $errors[] = __('The command :name must start with the plugin’s code and a colon, :prefix, and not with teme:.', ['name' => $command['name'], 'prefix' => $code.':']);
            }
        }

        return $errors;
    }

    /**
     * Why a started plugin can't answer for what it declares: it declares
     * commands, and doesn't implement HasCommands.
     *
     * @param  array<array-key, mixed>  $contributes
     *
     * @return list<string>
     */
    public static function startErrors(array $contributes, object $plugin): array
    {
        if (self::read($contributes['commands'] ?? []) === [] || $plugin instanceof HasCommands) {
            return [];
        }

        return [__('It declares commands but doesn’t implement :contract.', ['contract' => HasCommands::class])];
    }

    /**
     * @return list<string>
     */
    private static function partErrors(string $command, string $kind, mixed $parts): array
    {
        if (! is_array($parts) || ! array_is_list($parts) || count($parts) > self::MOST_PARTS) {
            return [__('The command :name may declare at most :most :kind, as a list.', ['name' => $command, 'most' => self::MOST_PARTS, 'kind' => $kind])];
        }

        $errors = [];
        $seen = [];
        $allowed = $kind === 'arguments' ? ['name', 'description', 'required'] : ['name', 'description', 'value'];
        $flag = $kind === 'arguments' ? 'required' : 'value';

        foreach ($parts as $part) {
            $name = is_array($part) && is_string($part['name'] ?? null) ? $part['name'] : null;

            if (! is_array($part) || array_diff(array_keys($part), $allowed) !== [] || $name === null || preg_match(self::PART, $name) !== 1 || in_array($name, self::RESERVED, true)) {
                $errors[] = __('Each of the command :name’s :kind needs a name of lower-case letters, digits and hyphens that Teme doesn’t use itself, and may say only :allowed.', ['name' => $command, 'kind' => $kind, 'allowed' => implode(', ', $allowed)]);

                continue;
            }

            if (isset($seen[$name])) {
                $errors[] = __('The command :name lists :part more than once.', ['name' => $command, 'part' => $name]);
            }

            $seen[$name] = true;

            if (! is_string($part['description'] ?? null) || mb_strlen($part['description']) > 120 || preg_match('/[\r\n]/', $part['description']) === 1) {
                $errors[] = __('Each of the command :name’s :kind needs a one-line description of up to 120 characters.', ['name' => $command, 'kind' => $kind]);
            }

            if (isset($part[$flag]) && ! is_bool($part[$flag])) {
                $errors[] = __('The command :name’s :part says :flag as true or false.', ['name' => $command, 'part' => $name, 'flag' => $flag]);
            }
        }

        return $errors;
    }
}

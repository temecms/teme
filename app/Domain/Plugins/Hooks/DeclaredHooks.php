<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Hooks;

use App\Domain\Plugins\Manifest;
use ReflectionClass;
use Teme\Contracts\Hooks\Collect;
use Teme\Contracts\Hooks\Hook;
use Teme\Contracts\Hooks\HookContract;
use Teme\Contracts\Hooks\Notification;
use Teme\Contracts\Hooks\Transform;
use Teme\Contracts\Plugins\Capability;

/**
 * The hooks a plugin declares for other plugins, `contributes.hooks`
 * (docs/plans/plugin-sockets-3.md): their shape as the manifest gives
 * them, and, once the plugin's code is loaded, whether each class is a hook
 * the plugin may send.
 */
final class DeclaredHooks
{
    /** A declared hook's short name; its full name is `{code}:{name}`. */
    public const string NAME = '/^[A-Z][A-Za-z]{2,47}$/D';

    /** A hook another plugin declares, as a listener's manifest names it. */
    public const string FULL_NAME = '/^([a-z0-9][a-z0-9-]{1,39}):([A-Z][A-Za-z]+)$/D';

    /** A class name as a manifest writes it. */
    public const string CLASS_NAME = '/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/D';

    /** At most this many hooks a plugin. */
    public const int MOST = 10;

    /** The verbs a plugin's own hook may take in this round. */
    private const array VERBS = [Notification::class, Transform::class, Collect::class];

    /** Namespaces no plugin's hook may be in, whatever its composer.json claims. */
    private const array RESERVED = ['Teme\\', 'App\\'];

    /**
     * The plugin's declared hooks, each with its name, its class without a
     * leading backslash and its description; none when the list's shape is wrong.
     *
     * @return list<array{name: string, class: string, description: string}>
     */
    public static function of(Manifest $manifest): array
    {
        $hooks = $manifest->contributes()['hooks'] ?? null;

        if (! is_array($hooks) || ! array_is_list($hooks)) {
            return [];
        }

        $declared = [];

        foreach ($hooks as $hook) {
            if (is_array($hook) && is_string($hook['name'] ?? null) && is_string($hook['class'] ?? null)) {
                $declared[] = [
                    'name' => $hook['name'],
                    'class' => ltrim($hook['class'], '\\'),
                    'description' => is_string($hook['description'] ?? null) ? $hook['description'] : '',
                ];
            }
        }

        return $declared;
    }

    /**
     * Why `contributes.hooks` can't be used, or [] when it can.
     *
     * @return list<string>
     */
    public static function errors(mixed $hooks): array
    {
        if (! is_array($hooks) || ! array_is_list($hooks)) {
            return [__('contributes.hooks must be a list of hooks.')];
        }

        $errors = count($hooks) > self::MOST ? [__('A plugin can declare at most 10 hooks.')] : [];
        $names = [];
        $classes = [];

        foreach ($hooks as $hook) {
            if (! is_array($hook) || array_diff(array_keys($hook), ['name', 'class', 'description']) !== []) {
                $errors[] = __('A declared hook has a name, a class and a description, and nothing else.');

                continue;
            }

            $name = is_string($hook['name'] ?? null) ? $hook['name'] : '';
            $class = is_string($hook['class'] ?? null) ? $hook['class'] : '';
            $description = is_string($hook['description'] ?? null) ? trim($hook['description']) : '';

            if (preg_match(self::NAME, $name) !== 1) {
                $errors[] = __('A declared hook’s name must be 3 to 48 letters, starting with a capital letter.');
            } elseif (isset($names[$name])) {
                $errors[] = __('Two hooks can’t share the name :name.', ['name' => $name]);
            }

            if (preg_match(self::CLASS_NAME, $class) !== 1) {
                $errors[] = __('The hook :name must name its class.', ['name' => $name]);
            } elseif (isset($classes[strtolower(ltrim($class, '\\'))])) {
                $errors[] = __('Two hooks can’t share the class :class.', ['class' => ltrim($class, '\\')]);
            }

            if ($description === '' || mb_strlen($description) > 200) {
                $errors[] = __('The hook :name needs a description of 1 to 200 characters.', ['name' => $name]);
            }

            $names[$name] = true;
            $classes[strtolower(ltrim($class, '\\'))] = true;
        }

        return $errors;
    }

    /**
     * Why one of the plugin's declared hooks can't be sent, once its code is
     * loaded, or null when each can: every class is a Notification,
     * Transform or Collect hook in one of the plugin's own namespaces, never
     * core's nor another found plugin's, defined in the plugin's own
     * package, whose capability, if it names one, the plugin asks for. A composer.json may claim any prefix, so the namespace alone
     * proves nothing.
     *
     * @param  list<string>  $namespaces  the plugin's autoloaded namespaces, each ending in a backslash
     * @param  string  $path  the plugin's folder, or Composer's install path for it
     * @param  list<string>  $others  the namespaces every other found plugin autoloads
     */
    public static function refusal(Manifest $manifest, array $namespaces, string $path, array $others = []): ?string
    {
        foreach (self::of($manifest) as $hook) {
            $words = ['name' => $hook['name'], 'class' => $hook['class']];

            if (! self::inside($hook['class'], $namespaces) || self::inside($hook['class'], [...self::RESERVED, ...$others])) {
                return __('Its hook :name names :class, which isn’t in the plugin’s own namespace.', $words);
            }

            if (! self::isHook($hook['class'])) {
                return __('Its hook :name names :class, which isn’t a Notification, Transform or Collect hook.', $words);
            }

            /** @var class-string<Hook> $class */
            $class = $hook['class'];
            $reflection = new ReflectionClass($class);

            if (! self::ownFile($reflection->getFileName(), $path, $manifest->name())) {
                return __('Its hook :name names :class, which isn’t in the plugin’s own files.', $words);
            }

            $capability = $reflection->getAttributes(HookContract::class)[0]->newInstance()->capability;

            if ($capability !== null && (Capability::tryFrom($capability) === null || ! in_array($capability, $manifest->capabilities(), true))) {
                return __('Its hook :name names :class, which needs :capability, which the plugin doesn’t ask for.', [...$words, 'capability' => $capability]);
            }
        }

        return null;
    }

    /**
     * The verb a declared hook's class takes, by its short name, or '' for none.
     */
    public static function verb(string $class): string
    {
        foreach (self::VERBS as $verb) {
            if (is_subclass_of($class, $verb)) {
                return (new ReflectionClass($verb))->getShortName();
            }
        }

        return '';
    }

    /**
     * @param  list<string>  $namespaces
     */
    private static function inside(string $class, array $namespaces): bool
    {
        foreach ($namespaces as $namespace) {
            if ($namespace !== '' && str_starts_with(strtolower($class), strtolower($namespace))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the file is the plugin's own: inside its folder, or in a
     * package whose nearest composer.json above the file names the plugin.
     * PHP loads a class once a process, so the second answers for a copy
     * of the same package.
     */
    private static function ownFile(string|false $file, string $folder, string $name): bool
    {
        $file = $file === false ? false : realpath($file);
        $folder = realpath($folder);

        if ($file === false) {
            return false;
        }

        if ($folder !== false && str_starts_with($file, rtrim($folder, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            return true;
        }

        for ($directory = dirname($file); $directory !== dirname($directory); $directory = dirname($directory)) {
            if (is_file($directory.'/composer.json')) {
                $composer = json_decode((string) file_get_contents($directory.'/composer.json'), true);

                return is_array($composer) && ($composer['name'] ?? null) === $name;
            }
        }

        return false;
    }

    /**
     * A class, with core's attribute, that takes exactly one of the verbs a
     * plugin's own hook may take, and none of the others.
     */
    private static function isHook(string $class): bool
    {
        if (! class_exists($class) || ! is_subclass_of($class, Hook::class)) {
            return false;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->getAttributes(HookContract::class) === []) {
            return false;
        }

        $verbs = array_filter(['Notification', 'Transform', 'Gate', 'Resolve', 'Collect'], fn (string $verb): bool => is_subclass_of($class, 'Teme\\Contracts\\Hooks\\'.$verb));

        return count($verbs) === 1 && in_array('Teme\\Contracts\\Hooks\\'.reset($verbs), self::VERBS, true);
    }
}

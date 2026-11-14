<?php

declare(strict_types=1);

namespace App\Domain\Import\Planning;

use App\Domain\Import\Operations\StartImportRun;
use App\Domain\Import\Phases\Users\WordPressRoles;
use App\Domain\Themes\ActiveTheme;
use App\Domain\Themes\TemplateChoice;
use App\Domain\Users\Queries\AuthorOptions;
use App\Domain\Users\Queries\RoleOptions;
use Illuminate\Validation\ValidationException;

/**
 * The choices an import needs (I-227): where each WordPress menu goes, what
 * each page template becomes, the role for each custom role, who owns items
 * whose author has gone, which outside sites' images come into the library,
 * and the pause between requests. Offered from a site's analysis and Teme's
 * own lists, and checked against them.
 */
final readonly class ImportChoices
{
    /** The pauses between requests to the old site, in milliseconds, with their words. */
    public const array PAUSES = [0 => 'No pause', 500 => 'Half a second', 1000 => 'One second', 2000 => 'Two seconds', 5000 => 'Five seconds'];

    /** WordPress's page templates belong to its pages, which arrive as Teme's built-in page type (I-443). */
    private const string PAGE_TYPE = 'page';

    private const string CHANGED = 'Analyze the site again: its report has changed.';

    /** A custom role's choice that leaves its people behind, as the users phase reads it. */
    public const string NONE = 'none';

    public function __construct(private RoleOptions $roles, private AuthorOptions $authors, private ActiveTheme $theme) {}

    /**
     * Returns what the choices step draws: each choice's options, and its
     * default, for this site's analysis and the person choosing.
     *
     * @return array{menuLocations: list<array{key: string, value: string}>, slots: list<array{value: string, label: string}>, templates: list<array{key: string, count: int, value: string}>, themeTemplates: list<array{value: string, label: string}>, roles: list<array{key: string, value: string}>, roleOptions: list<array{value: string, label: string}>, owner: string, people: list<array{value: string, label: string}>, outsideHosts: list<array{host: string, count: int}>, pause: int, pauses: list<array{value: int, label: string}>}
     */
    public function form(SiteAnalysis $analysis, string $personId): array
    {
        $slots = $this->slots();
        $people = $this->people();

        return [
            'menuLocations' => array_map(fn (string $key): array => ['key' => $key, 'value' => array_key_exists($key, $slots) ? $key : ''], $analysis->menuLocations()),
            'slots' => self::options($slots),
            'templates' => array_map(fn (string $file, int $count): array => ['key' => $file, 'count' => $count, 'value' => ''], array_keys($analysis->templates()), $analysis->templates()),
            'themeTemplates' => self::options($this->templates()),
            'roles' => array_map(fn (string $role, array $capabilities): array => ['key' => $role, 'value' => self::suggestedRole($capabilities) ?? self::NONE], array_keys($analysis->customRoles()), $analysis->customRoles()),
            'roleOptions' => [...$this->roles->all(), ['value' => self::NONE, 'label' => 'Leave behind']],
            'owner' => array_key_exists($personId, $people) ? $personId : (string) (array_key_first($people) ?? ''),
            'people' => self::options($people),
            'outsideHosts' => array_map(fn (string $host, int $count): array => ['host' => $host, 'count' => $count], array_keys($analysis->outsideHosts()), $analysis->outsideHosts()),
            'pause' => StartImportRun::OPTIONS['pause_ms'],
            'pauses' => array_map(fn (int $value, string $label): array => ['value' => $value, 'label' => $label], array_keys(self::PAUSES), self::PAUSES),
        ];
    }

    /**
     * Returns the run's choices and its pause from what the form sent, each
     * checked against what the analysis lists and Teme offers.
     *
     * @param  array<string, mixed>  $input
     *
     * @return array{choices: array{menu_locations: array<string, string|null>, templates: array<string, string|null>, roles: array<string, string>, owner: string, outside_images: list<string>}, pauseMs: int}
     *
     * @throws ValidationException under each field that doesn't fit
     */
    public function validated(array $input, SiteAnalysis $analysis): array
    {
        $errors = [];

        [$locations, $errors] = self::mapped($input, 'menu_locations', $analysis->menuLocations(), array_keys($this->slots()), true, 'Choose one of the theme’s places for menus, or Don’t place it.', $errors);
        [$templates, $errors] = self::mapped($input, 'templates', array_keys($analysis->templates()), array_keys($this->templates()), true, 'Choose one of the theme’s templates.', $errors);
        [$roles, $errors] = self::mapped($input, 'roles', array_keys($analysis->customRoles()), [...array_column($this->roles->all(), 'value'), self::NONE], false, 'Choose one of Teme’s roles.', $errors);

        $owner = is_scalar($input['owner'] ?? null) ? (string) $input['owner'] : '';

        if (! array_key_exists($owner, $this->people())) {
            $errors['owner'] = 'Choose someone who can write on Teme.';
        }

        $hosts = $input['outside_images'] ?? [];
        $hosts = is_array($hosts) ? array_values(array_unique(array_map(fn (mixed $host): string => is_scalar($host) ? strtolower((string) $host) : '', $hosts))) : null;

        if ($hosts === null || array_diff($hosts, array_keys($analysis->outsideHosts())) !== []) {
            $errors['outside_images'] = 'Tick only the sites listed.';
        }

        $pause = $input['pause'] ?? (string) StartImportRun::OPTIONS['pause_ms'];

        if (! is_numeric($pause) || ! array_key_exists((int) $pause, self::PAUSES)) {
            $errors['pause'] = 'Choose one of the pauses listed.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'choices' => [
                'menu_locations' => $locations,
                'templates' => $templates,
                // Every role was checked above, so none is empty here.
                'roles' => array_map(fn (?string $role): string => (string) $role, $roles),
                'owner' => $owner,
                'outside_images' => $hosts ?? [],
            ],
            'pauseMs' => (int) $pause,
        ];
    }

    /**
     * The nearest Teme role at or below a role with these WordPress
     * capabilities, by the users phase's own ladder: the highest whose
     * needs it fully holds; null when it can't even write, as a reader can't.
     *
     * @param  list<string>  $capabilities
     */
    public static function suggestedRole(array $capabilities): ?string
    {
        return WordPressRoles::nearest($capabilities);
    }

    /**
     * One map of choices: every key the analysis lists, each to one of the
     * allowed values, or to nothing where nothing is allowed; any key the
     * analysis doesn't list refuses the whole map.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $keys
     * @param  list<string>  $allowed
     * @param  array<string, string>  $errors
     *
     * @return array{0: array<string, string|null>, 1: array<string, string>}
     */
    private static function mapped(array $input, string $field, array $keys, array $allowed, bool $nothingAllowed, string $words, array $errors): array
    {
        $sent = is_array($input[$field] ?? null) ? $input[$field] : [];
        $kept = [];

        foreach (array_keys($sent) as $key) {
            if (! in_array((string) $key, $keys, true)) {
                $errors[$field] = self::CHANGED;
            }
        }

        foreach ($keys as $key) {
            $value = is_scalar($sent[$key] ?? null) ? (string) $sent[$key] : '';

            if (($value === '' && ! $nothingAllowed) || ($value !== '' && ! in_array($value, $allowed, true))) {
                $errors["{$field}.{$key}"] = $words;
            }

            $kept[$key] = $value === '' ? null : $value;
        }

        return [$kept, $errors];
    }

    /**
     * The active theme's places for menus: key to label.
     *
     * @return array<string, string>
     */
    private function slots(): array
    {
        $theme = $this->theme->get();

        return $theme !== null && $theme->usable() ? ($theme->manifest?->navigations() ?? []) : [];
    }

    /**
     * The active theme's page templates: key to label, its default left out.
     *
     * @return array<string, string>
     */
    private function templates(): array
    {
        return TemplateChoice::declared(self::PAGE_TYPE);
    }

    /**
     * Who may own content: ID to name.
     *
     * @return array<int|string, string>
     */
    private function people(): array
    {
        $people = [];

        foreach ($this->authors->all() as $person) {
            $people[(string) $person['value']] = $person['label'];
        }

        return $people;
    }

    /**
     * @param  array<string|int, string>  $map
     *
     * @return list<array{value: string, label: string}>
     */
    private static function options(array $map): array
    {
        return array_map(fn (string|int $value, string $label): array => ['value' => (string) $value, 'label' => $label], array_keys($map), $map);
    }
}

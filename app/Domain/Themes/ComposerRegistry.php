<?php

/**
 * The composers that add data to theme views (I-251): core's first, then
 * the active theme's in its manifest's order, each built once. One view
 * composer, registered on `theme::*`, runs them for the views each names.
 * The list is built again whenever the active theme changes, never added
 * to, so no composer runs twice. A key a view already has is an error,
 * unless it came down from the view that drew it: an @included partial and
 * an @extends layout get every variable of their view, so a composer that
 * serves both meets the value it gave before, which is kept. A name in
 * RESERVED is an error from a theme's composer, whatever the view has.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Themes;

use App\Domain\Languages\PageLanguage;
use App\Domain\Menus\NavigationComposer;
use App\Domain\Site\LanguageComposer;
use App\Domain\Themes\CoreComposers\SiteComposer;
use App\Domain\Themes\CoreComposers\ThemeSettingsComposer;
use App\Domain\Themes\Exceptions\ComposerKeyCollision;
use App\Domain\Themes\Exceptions\ComposerNotAllowed;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionNamedType;
use Teme\Contracts\Themes\ViewComposer;

final class ComposerRegistry
{
    /** @var list<class-string<ViewComposer>> core's composers, run before any theme's */
    private const array CORE = [SiteComposer::class, LanguageComposer::class, NavigationComposer::class, ThemeSettingsComposer::class];

    /** @var list<string> the names core keeps for a page's language and for plugin page output, which no theme's composer may return (issue 20) */
    public const array RESERVED = ['language', 'direction', 'alternates', 'plugins'];

    /** @var list<ViewComposer> */
    private array $composers = [];

    private bool $booted = false;

    private ?string $bootedFor = null;

    private ?ViewIntent $intent = null;

    private ?object $model = null;

    /** @var array<string, list<string>> each composer that ran, with the keys it added, outside production */
    private array $ran = [];

    /** @var array<class-string, array<string, list<mixed>>> the values each composer gave each key on this page */
    private array $given = [];

    public function __construct(private readonly ActiveTheme $active, private readonly PageLanguage $language) {}

    /**
     * Why a theme's composer may not run, or null when it may: it must be a
     * ViewComposer whose constructor asks only for Teme\Contracts interfaces.
     */
    public static function refusal(string $class): ?ComposerNotAllowed
    {
        if (! class_exists($class)) {
            return ComposerNotAllowed::missing($class);
        }

        $reflection = new ReflectionClass($class);

        if (! $reflection->implementsInterface(ViewComposer::class)) {
            return ComposerNotAllowed::notAComposer($class);
        }

        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof ReflectionNamedType && ! $type->isBuiltin() ? $type->getName() : null;

            if ($name === null || ! interface_exists($name) || ! str_starts_with($name, 'Teme\\Contracts\\')) {
                return ComposerNotAllowed::asksFor($class, $type === null ? "an untyped \${$parameter->getName()}" : (string) $type);
            }
        }

        return null;
    }

    /**
     * Builds the list for this theme, or for core alone: it replaces the
     * list before it, never adds to it.
     */
    public function boot(?Theme $theme): void
    {
        $composers = array_map(fn (string $class): ViewComposer => app($class), self::CORE);

        foreach ($theme?->manifest?->composers() ?? [] as $class) {
            // Discovery already broke a theme whose composer may not run;
            // this guards a theme cache left from before the rule was broken.
            $refusal = self::refusal($class);

            if ($refusal !== null) {
                throw $refusal;
            }

            /** @var ViewComposer $composer */
            $composer = app($class);
            $composers[] = $composer;
        }

        $this->composers = $composers;
        $this->booted = true;
        $this->bootedFor = $theme?->code;
    }

    /**
     * Starts a page: the theme views rendered next belong to it, for the
     * composers' context and the document shell, which reads the page's
     * view model here, and the records of which composers ran, and what
     * they gave, start empty, so they hold this page's only.
     */
    public function startPage(?ViewIntent $intent, ?object $model = null): void
    {
        $this->intent = $intent;
        $this->model = $model;
        $this->ran = [];
        $this->given = [];
    }

    /**
     * The page being drawn's intent, or null outside a page.
     */
    public function intent(): ?ViewIntent
    {
        return $this->intent;
    }

    /**
     * The page being drawn's view model, the template's `$page`, or null outside a page.
     */
    public function model(): ?object
    {
        return $this->model;
    }

    /**
     * Runs every composer that serves this theme view.
     */
    public function compose(View $view): void
    {
        $theme = $this->active->get();

        if (! $this->booted || $this->bootedFor !== $theme?->code) {
            $this->boot($theme);
        }

        $name = Str::after($view->name(), 'theme::');
        $context = new ThemeContext($name, $this->intent, $this->language->tag(), $view);

        foreach ($this->composers as $composer) {
            if (! Str::is($composer->views(), $name)) {
                continue;
            }

            $added = [];

            foreach ($composer->with($context) as $key => $value) {
                if (in_array($key, self::RESERVED, true) && ! in_array($composer::class, self::CORE, true)) {
                    $reserved = ComposerKeyCollision::reserved($composer::class, (string) $key, $name, (string) $theme?->code);

                    app()->isProduction() ? report($reserved) : throw $reserved;

                    continue;
                }

                if (array_key_exists($key, $view->getData())) {
                    // Came down from the view that drew this one, which this
                    // composer gave it: kept, as it was.
                    if (in_array($view->getData()[$key], $this->given[$composer::class][$key] ?? [], true)) {
                        continue;
                    }

                    $collision = ComposerKeyCollision::for($composer::class, $key, $name);

                    // In production the value already there is kept, and the collision logged.
                    app()->isProduction() ? report($collision) : throw $collision;

                    continue;
                }

                $view->with($key, $value);
                $this->given[$composer::class][$key][] = $value;
                $added[] = $key;
            }

            if (! app()->isProduction()) {
                $short = class_basename($composer);
                $this->ran[$short] = array_values(array_unique([...$this->ran[$short] ?? [], ...$added]));
            }
        }
    }

    /**
     * The composers that ran, each with the keys it added, such as
     * "SiteComposer(site, isFront)"; empty in production, where none is kept.
     *
     * @return list<string>
     */
    public function ran(): array
    {
        return array_map(fn (string $composer, array $keys): string => $composer.'('.implode(', ', $keys).')', array_keys($this->ran), $this->ran);
    }
}

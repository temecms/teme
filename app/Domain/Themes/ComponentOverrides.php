<?php

/**
 * The core components a theme may replace with its own view (issue 788): the list, the
 * contract each one is at, and the view each component draws. A theme's view is used only
 * when its manifest declares the override and the view file is there; anything else draws
 * core's. The document and the script helpers are never on the list
 * (docs/guides/developers/themes/components.md).
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Themes;

use App\View\Components\Teme\BetweenItems;
use App\View\Components\Teme\CommentForm;
use App\View\Components\Teme\Image;
use App\View\Components\Teme\Languages;
use App\View\Components\Teme\Navigation;
use App\View\Components\Teme\Region;
use Illuminate\Contracts\View\View;

/**
 * Chooses the view a core component draws: the active theme's override when its manifest
 * declares it and ships the file, else core's own view. An override is drawn from its file
 * path, so the theme's view composers don't run on it, as they don't run on core's own views.
 * It also says the contract number each component is at, which the theme check compares with.
 */
class ComponentOverrides
{
    /**
     * The components a theme may override, by name, with the class that draws
     * each. Each class declares the contract its override is written against.
     *
     * @var array<string, class-string>
     */
    public const array CLASSES = [
        'region' => Region::class,
        'navigation' => Navigation::class,
        'languages' => Languages::class,
        'image' => Image::class,
        'between-items' => BetweenItems::class,
        'comment-form' => CommentForm::class,
    ];

    /** @var array<string, bool> whether each override file is there, by its path, read once per request */
    private array $present = [];

    public function __construct(private readonly ActiveTheme $active) {}

    /**
     * The view a component draws: the theme's override file when the active theme declares
     * and ships it, else core's view by its name.
     */
    public function render(string $name, string $core): View
    {
        $file = $this->overrideFile($name);

        return $file === null ? view($core) : view()->file($file);
    }

    /**
     * The contract number core's component is at, as its class declares it.
     */
    public function contractOf(string $name): int
    {
        return self::CLASSES[$name]::CONTRACT;
    }

    /**
     * The path of the active theme's override view for a component, or null when the theme
     * doesn't declare that override or doesn't ship its file.
     */
    public function overrideFile(string $name): ?string
    {
        $theme = $this->active->get();

        if ($theme === null || ! array_key_exists($name, $theme->manifest?->overrides() ?? [])) {
            return null;
        }

        $file = $theme->path.'/views/components/'.$name.'.blade.php';

        if (! array_key_exists($file, $this->present)) {
            $this->present[$file] = is_file($file);
        }

        return $this->present[$file] ? $file : null;
    }
}

<?php

/**
 * The core components a theme may replace with its own view (#788): the list, the
 * contract each one is at, and the choice each component makes when it draws. A
 * theme's view is used only when its manifest declares the override and ships the
 * view; anything else draws core's. The document and the script helpers are never
 * on the list (docs/guides/developers/themes/components.md).
 *
 * @package Teme
 * @since   next
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

/**
 * Chooses which view a core component draws: the active theme's override when its
 * manifest declares it and ships the file, else core's own view. It also says the
 * contract number each component is at, which the theme check compares against.
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

    public function __construct(private readonly ActiveTheme $active) {}

    /**
     * The view a component draws: the active theme's own view of it when the
     * theme's manifest declares the override and ships the file, else core's.
     */
    public function view(string $name, string $core): string
    {
        $theme = $this->active->get();

        if ($theme === null || ! array_key_exists($name, $theme->manifest?->overrides() ?? [])) {
            return $core;
        }

        return is_file($theme->path.'/views/components/'.$name.'.blade.php') ? 'theme::components.'.$name : $core;
    }

    /**
     * The contract number core's component is at, as its class declares it.
     */
    public function contractOf(string $name): int
    {
        return self::CLASSES[$name]::CONTRACT;
    }
}

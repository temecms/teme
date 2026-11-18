<?php

/**
 * Makes a theme the one the public site is drawn with, from Site › Themes:
 * the switch is drafted, so the brand colour is checked against the theme's
 * light background (I-457), and the whole design draft is published with
 * it, the theme's settings and menu places changed while it was previewed
 * among them.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Design\Operations;

use App\Domain\Design\Operations\Concerns\ManagesDesign;
use App\Domain\Design\Operations\Input\ActivateThemeInput;
use App\Domain\Design\Operations\Input\PublishDesignInput;
use App\Domain\Design\Operations\Input\SaveDesignDraftInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Themes\Theme;
use App\Domain\Themes\ThemeRegistry;
use Illuminate\Validation\ValidationException;
use LogicException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Settings\SettingsReader;

final class ActivateTheme extends BaseOperation
{
    use ManagesDesign;

    public function __construct(
        private readonly ThemeRegistry $themes,
        private readonly SettingsReader $settings,
        private readonly SaveDesignDraft $save,
        private readonly PublishDesign $publish,
    ) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var ActivateThemeInput $input */
        $this->authorizeDesign($input);
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ActivateThemeInput $input */
        $theme = $this->themes->find($input->code);

        if ($theme === null) {
            throw ValidationException::withMessages(['theme' => __('No theme called “:code” is installed.', ['code' => $input->code])]);
        }

        $name = $theme->manifest?->name() ?? $theme->code;

        if (! $theme->usable()) {
            throw ValidationException::withMessages(['theme' => __(':name can’t be used: :error', ['name' => $name, 'error' => $theme->errors[0] ?? ''])]);
        }

        if ($theme->code === $this->settings->string(self::DESIGN_GROUP, 'theme')) {
            throw ValidationException::withMessages(['theme' => __(':name is already the active theme.', ['name' => $name])]);
        }
    }

    /**
     * @return Theme the theme the site is now drawn with
     */
    protected function perform(OperationInput $input): Theme
    {
        /** @var ActivateThemeInput $input */
        // Drafted first, whatever the draft held: the theme pressed is the
        // one that goes live, and SaveDesignDraft checks the brand colour
        // against it under the lock.
        $this->save->execute(new SaveDesignDraftInput([self::DESIGN_GROUP => ['theme' => $input->code]], $input->actorId));
        $this->publish->execute(new PublishDesignInput($input->actorId));

        return $this->themes->find($input->code) ?? throw new LogicException("No theme [{$input->code}].");
    }
}

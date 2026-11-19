<?php

/**
 * Writes a settings group's words in one of the site's other languages
 * (I-3360): the site title, tagline, title templates or a theme slot's menu
 * that readers of that language see. The person must hold the group's own
 * ability, a sensitive group asks for a fresh password confirmation, and a
 * form opened on an older version is refused. The default language's
 * values are left as they were.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Settings\Operations;

use App\Domain\Languages\SiteLanguages;
use App\Domain\Settings\Operations\Input\TranslateSettingsInput;
use App\Domain\Settings\SettingRules;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsWriter;
use App\Domain\Settings\TranslatableSettings;
use App\Domain\Support\Operations\BaseOperation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

final class TranslateSettings extends BaseOperation implements RequiresElevation
{
    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly SettingsWriter $writer,
    ) {}

    public function requiresElevation(OperationInput $input): bool
    {
        /** @var TranslateSettingsInput $input */
        return $this->registry->find($input->group)?->sensitive === true;
    }

    protected function authorize(OperationInput $input): void
    {
        /** @var TranslateSettingsInput $input */
        $definition = $this->registry->find($input->group);
        $actor = $input->actorId() === null ? null : User::query()->find($input->actorId());

        if ($definition === null || $actor === null || ! $actor->can($definition->ability)) {
            throw new AuthorizationException('Not permitted to change these settings.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var TranslateSettingsInput $input */
        $definition = $this->registry->find($input->group) ?? throw new AuthorizationException('Not permitted to change these settings.');
        $languages = app(SiteLanguages::class);

        if (! in_array($input->language, $languages->others(), true)) {
            throw ValidationException::withMessages(['language' => __(":language isn't one of the site's other languages.", ['language' => $languages->label($input->language)])]);
        }

        $problems = [];

        foreach ([...array_keys($input->values), ...$input->reset] as $key) {
            if ($definition->setting((string) $key) === null || ! TranslatableSettings::has($input->group, (string) $key)) {
                $problems['values.'.$key] = __('This setting has no words per language.');
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        // An empty word is no word: the default language's shows (SettingsWriter::writeTranslations()).
        SettingRules::validateValues($definition, array_filter($input->values, fn (mixed $value): bool => $value !== '' && $value !== null));
    }

    /**
     * @return array{group: string, language: string, version: int}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var TranslateSettingsInput $input */
        $version = $this->writer->writeTranslations(
            $input->group,
            $input->language,
            $input->values,
            $input->reset,
            $input->expectedVersion,
            $input->actorId(),
            $input->origin,
        );

        return ['group' => $input->group, 'language' => $input->language, 'version' => $version];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\ImportTranslatedSettingsInput;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\SettingRules;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsWriter;
use App\Domain\Settings\TranslatableSettings;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

/**
 * Writes an imported site's words in another language (I-3446), as
 * starting an import allows, the way TranslateSettings would: the site
 * title, the tagline, a title template or the menu a theme slot shows in
 * that language. A word this site already has in that language is kept,
 * so a person's translation, or a menu they chose, is never replaced.
 */
final class ImportTranslatedSettings extends BaseOperation
{
    use AuthorizesImports;

    public function __construct(private readonly SettingsRegistry $registry, private readonly SettingsWriter $writer) {}

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ImportTranslatedSettingsInput $input */
        $definition = $this->registry->find($input->group)
            ?? throw ValidationException::withMessages(['group' => "Teme has no settings group [{$input->group}]."]);
        $languages = app(SiteLanguages::class);

        if (! in_array($input->language, $languages->others(), true)) {
            throw ValidationException::withMessages(['language' => __(":language isn't one of the site's other languages.", ['language' => $languages->label($input->language)])]);
        }

        foreach (array_keys($input->values) as $key) {
            if ($definition->setting((string) $key) === null || ! TranslatableSettings::has($input->group, (string) $key)) {
                throw ValidationException::withMessages(['values.'.$key => __('This setting has no words per language.')]);
            }
        }

        SettingRules::validateValues($definition, array_filter($input->values, fn (mixed $value): bool => $value !== '' && $value !== null));
    }

    /**
     * @return array{written: list<string>, kept: list<string>}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ImportTranslatedSettingsInput $input */
        SettingsGroupRecord::query()->insertOrIgnore(['group_key' => $input->group, 'overrides' => '{}', 'version' => 0]);
        $record = SettingsGroupRecord::query()->lockForUpdate()->findOrFail($input->group);
        $had = (array) (($record->translations ?? [])[$input->language] ?? []);
        $values = [];
        $kept = [];

        foreach ($input->values as $key => $value) {
            if (array_key_exists($key, $had) && $had[$key] !== $value) {
                $kept[] = (string) $key;
            } else {
                $values[(string) $key] = $value;
            }
        }

        if ($values !== []) {
            $this->writer->writeTranslations($input->group, $input->language, $values, [], null, $input->actorId(), 'import');
        }

        return ['written' => array_keys($values), 'kept' => $kept];
    }
}

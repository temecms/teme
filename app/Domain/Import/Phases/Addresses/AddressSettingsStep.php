<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Addresses;

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Operations\ClearRunMessages;
use App\Domain\Import\Operations\ImportSettings;
use App\Domain\Import\Operations\Input\ClearRunMessagesInput;
use App\Domain\Import\Operations\Input\ImportSettingsInput;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\ImportStopped;
use App\Domain\Import\Phases\Settings\WordPressSettings;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Source\SourceReader;
use App\Domain\Import\Translations\ImportedLanguages;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Before anyone or anything is written (I-393): the old site's address
 * settings, its time zone and its language, so every item, term and author
 * the import makes takes the address it had. One job; a run carried on runs
 * it again, and it keeps what a person changed since.
 */
final readonly class AddressSettingsStep implements StepPhase
{
    /** The map row this step's settings are kept on. */
    public const string OBJECT = 'address-settings';

    private const string PLAIN = 'The old site uses plain addresses, such as /?p=12, which Teme can’t answer, so Teme kept its own address settings. The address check lists every old address that doesn’t answer.';

    private const string STOPPED = 'Under the old site’s address settings, things already in Teme would share an address, so the import stopped. The report lists them: rename one of each pair, then carry on the import.';

    public function key(): string
    {
        return 'address-settings';
    }

    public function order(): int
    {
        return 100;
    }

    public function label(): string
    {
        return 'Address settings';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        app(ClearRunMessages::class)->execute(new ClearRunMessagesInput($context->runId, 'address.', $context->actorId()));

        $answer = $reader->whole('settings');
        $options = is_array($answer['options'] ?? null) ? $answer['options'] : [];
        // With Polylang, the default language is Polylang's own, not WordPress's locale (I-3446).
        $polylang = app(ImportedLanguages::class)->refresh($context->sourceId, $reader);
        $locale = $polylang->active && $polylang->defaultTag() !== null ? (string) $polylang->defaultTag() : (is_string($answer['locale'] ?? null) ? $answer['locale'] : '');
        $early = WordPressSettings::early($options, $locale);
        $values = $early['values'] === [] ? [] : ['general' => $early['values']];
        $messages = $early['messages'];
        $structure = self::text($options['permalink_structure'] ?? '');

        if ($structure === '') {
            $messages[] = new ImportMessage('address.plain', self::PLAIN);
        } else {
            $categoryBase = self::text($options['category_base'] ?? '');
            $category = WordPressAddresses::temeTaxonomy(WordPressAddresses::CATEGORIES);
            $stripped = $category !== null && WordPressAddresses::stripsCategoryBase($this->categoryLink($reader), $structure, $categoryBase);
            $plan = WordPressAddresses::plan(
                $structure,
                $categoryBase,
                self::text($options['tag_base'] ?? ''),
                $stripped,
                WordPressAddresses::temeType(WordPressAddresses::POSTS),
                WordPressAddresses::temeType(WordPressAddresses::PAGES),
                $category?->key,
                WordPressAddresses::temeTaxonomy(WordPressAddresses::TAGS)?->key,
            );
            $values['addresses'] = $plan['values'];
            $messages = [...$messages, ...$plan['messages']];
        }

        try {
            $written = app(ImportSettings::class)->execute(new ImportSettingsInput($context->sourceId, $context->runId, self::OBJECT, $values, $context->actorId()));
        } catch (ValidationException $refused) {
            // Only a clash among Teme's own things stops the run; any other refusal is a fault, for the log.
            $clashes = array_filter($refused->errors(), fn (string $key): bool => preg_match('/^addresses\.\d+$/', $key) === 1, ARRAY_FILTER_USE_KEY);

            if ($clashes === [] || count($clashes) !== count($refused->errors())) {
                throw $refused;
            }

            $lines = array_map(fn (mixed $line): ImportMessage => ImportMessage::error('address.settings-clash', (string) $line), Arr::flatten($clashes));

            throw ImportStopped::because('address-settings', self::STOPPED, array_values($lines));
        }

        if ($written['kept'] !== []) {
            $messages[] = new ImportMessage('address.kept-here', 'Changed here since the last import, so kept as they are: '.WordPressSettings::labels($written['kept']).'.', ['settings' => $written['kept']]);
        }

        return StepResult::done($messages);
    }

    /**
     * The address of the first category the companion's terms list, or null.
     */
    private function categoryLink(SourceReader $reader): ?string
    {
        foreach ($reader->page('terms', [], 0, 200)->items as $item) {
            if (($item['taxonomy'] ?? null) === WordPressAddresses::CATEGORIES && is_string($item['link'] ?? null)) {
                return $item['link'];
            }
        }

        return null;
    }

    /**
     * An option's value as trimmed text: WordPress gives strings, numbers or nothing.
     */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}

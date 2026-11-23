<?php

/**
 * After the site's own settings (I-3446): Polylang's string translations
 * of the site title, the tagline and Yoast's title templates become that
 * language's words here; a template whose parts Teme has no token for, and
 * every other plugin's and the theme's strings, are said, not imported.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Languages;

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Operations\ClearRunMessages;
use App\Domain\Import\Operations\ImportTranslatedSettings;
use App\Domain\Import\Operations\Input\ClearRunMessagesInput;
use App\Domain\Import\Operations\Input\ImportTranslatedSettingsInput;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Source\SourceReader;
use App\Domain\Import\Translations\ImportedLanguages;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final readonly class LanguageWordsStep implements StepPhase
{
    /** Polylang's names for WordPress's own two strings, and the general setting each is. */
    private const array GENERAL = ['Site Title' => 'site_title', 'Tagline' => 'tagline'];

    /** Yoast's title template parts every template may hold, and Teme's token for each. */
    private const array TOKENS = [
        '%%title%%' => '{title}', '%%sitename%%' => '{site}', '%%sitedesc%%' => '{tagline}', '%%sep%%' => '{sep}',
        '%%page%%' => '{page}', '%%searchphrase%%' => '{search}', '%%excerpt%%' => '{excerpt}',
    ];

    /** An item's parts: its date, author, category and type, and the year, which here is the year it was published. */
    private const array ITEM_TOKENS = [
        '%%date%%' => '{date}', '%%name%%' => '{author}', '%%category%%' => '{term}', '%%primary_category%%' => '{term}',
        '%%pt_single%%' => '{type}', '%%pt_plural%%' => '{type}', '%%currentyear%%' => '{year}',
    ];

    /** An archive's parts naming what it lists, which Teme's archives give as their title. */
    private const array ARCHIVE_TOKENS = [
        '%%term_title%%' => '{title}', '%%name%%' => '{title}', '%%date%%' => '{title}', '%%pt_plural%%' => '{title}',
    ];

    public function __construct(private ImportedLanguages $imported) {}

    public function key(): string
    {
        return 'language-words';
    }

    public function order(): int
    {
        return 1060;
    }

    public function label(): string
    {
        return 'Words in each language';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        // An export file has none of this (docs/architecture/import.md).
        if ($context->readsFile()) {
            return StepResult::done();
        }

        app(ClearRunMessages::class)->execute(new ClearRunMessagesInput($context->runId, 'language-words.', $context->actorId()));

        $languages = $this->imported->for($context->sourceId);
        $site = app(SiteLanguages::class);
        $templates = self::templates();
        $messages = [];

        foreach ($languages->strings as $slug => $pairs) {
            $tag = $languages->language((string) $slug)['language'] ?? null;

            if ($tag === null || ! in_array($tag, $site->others(), true)) {
                continue;
            }

            $words = ['general' => [], SearchAndSocial::KEY => []];
            $left = [];

            foreach ($pairs as ['name' => $name, 'translation' => $translation]) {
                if (isset(self::GENERAL[$name])) {
                    $words['general'][self::GENERAL[$name]] = $translation;
                } elseif (isset($templates[$name]) && ($template = self::template($translation, str_starts_with($templates[$name], 'title_type_'))) !== null) {
                    $words[SearchAndSocial::KEY][$templates[$name]] = $template;
                } else {
                    $left[] = $name;
                }
            }

            foreach ($words as $group => $values) {
                if ($values !== []) {
                    $messages = [...$messages, ...$this->write($group, $tag, $values, $context)];
                }
            }

            if ($left !== []) {
                $listed = ['language' => $site->label($tag), 'count' => count($left)];
                $messages[] = new ImportMessage('language-words.left', trans_choice('{1} 1 string in :language, the theme’s or another plugin’s, wasn’t imported: Teme has no setting for it.|[2,*] :count strings in :language, the theme’s or other plugins’, weren’t imported: Teme has no setting for them.', count($left), $listed), [...$listed, 'names' => array_slice($left, 0, 20)]);
            }
        }

        return StepResult::done($messages);
    }

    /**
     * Writes one group's words in a language, saying what was kept or refused.
     *
     * @param  array<string, string>  $values
     *
     * @return list<ImportMessage>
     */
    private function write(string $group, string $tag, array $values, ImportContext $context): array
    {
        $language = app(SiteLanguages::class)->label($tag);

        try {
            $written = app(ImportTranslatedSettings::class)->execute(new ImportTranslatedSettingsInput($group, $tag, $values, $context->actorId()));
        } catch (ValidationException $refused) {
            $reason = (string) (Arr::first(Arr::flatten($refused->errors())) ?? '');

            return [new ImportMessage('language-words.refused', __('The old site’s words in :language couldn’t be copied: :reason', ['language' => $language, 'reason' => $reason]), ['language' => $tag, 'group' => $group])];
        }

        return $written['kept'] === [] ? [] : [new ImportMessage('language-words.kept-here', __('Changed here since in :language, so kept as they are: :keys.', ['language' => $language, 'keys' => implode(', ', $written['kept'])]), ['language' => $tag, 'keys' => $written['kept']])];
    }

    /**
     * Yoast's names for its title templates, as Polylang lists them, and
     * the Search and social setting each is here.
     *
     * @return array<string, string>
     */
    private static function templates(): array
    {
        $names = [
            'title-home-wpseo' => 'title_home', 'title-author-wpseo' => 'title_author', 'title-archive-wpseo' => 'title_date',
            'title-search-wpseo' => 'title_search', 'title-404-wpseo' => 'title_not_found',
        ];

        foreach (app(ContentTypeRegistry::class)->all() as $type) {
            if ($type->wordpress !== null) {
                $names['title-'.$type->wordpress] = 'title_type_'.$type->key;
                $names['title-ptarchive-'.$type->wordpress] = 'title_archive_'.$type->key;
            }
        }

        foreach (app(TaxonomyRegistry::class)->all() as $taxonomy) {
            if ($taxonomy->wordpress !== null) {
                $names['title-tax-'.$taxonomy->wordpress] = 'title_taxonomy_'.$taxonomy->key;
            }
        }

        $seo = app(SettingsRegistry::class)->find(SearchAndSocial::KEY);

        return array_filter($names, fn (string $key): bool => $seo?->setting($key) !== null);
    }

    /**
     * A Yoast title template in Teme's tokens, an item's or an archive's,
     * or null when it has a part Teme has no token for there.
     */
    private static function template(string $yoast, bool $item): ?string
    {
        $template = trim((string) preg_replace('/\s+/', ' ', strtr($yoast, [...self::TOKENS, ...($item ? self::ITEM_TOKENS : self::ARCHIVE_TOKENS)])));

        return str_contains($template, '%%') ? null : $template;
    }
}

<?php

/**
 * Gives the site an imported site's languages (I-3446), as starting an
 * import allows, the way AddSiteLanguage and ChangeTranslatedTypes would:
 * each language not already here, last, shown, under its prefix; the types
 * and taxonomies the old site didn't translate left untranslated, except
 * one that has something in a language already. A language Teme can't add
 * is answered with why, never half-written; one already here is left as
 * it is.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations;

use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\Input\ImportSiteLanguagesInput;
use App\Domain\Languages\LanguageLock;
use App\Domain\Languages\Operations\Concerns\WritesSiteLanguages;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Settings\SettingsReader;

final class ImportSiteLanguages extends BaseOperation
{
    use AuthorizesImports;
    use WritesSiteLanguages;

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ImportSiteLanguagesInput $input */
        $languages = $input->languages;

        foreach ($languages as $language) {
            if ($language['language'] === '' || $language['prefix'] === '') {
                throw ValidationException::withMessages(['languages' => __('Each language needs its tag and its prefix.')]);
            }
        }
    }

    /**
     * @return array{added: list<string>, refused: array<string, string>, translated: list<string>}
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ImportSiteLanguagesInput $input */
        // Alone: a type stops being translated here, as ChangeTranslatedTypes takes it.
        LanguageLock::exclusive();
        $lists = $this->current();
        $site = app(SiteLanguages::class);
        $added = [];
        $refused = [];

        foreach ($input->languages as ['language' => $tag, 'prefix' => $prefix]) {
            if ($tag === $site->defaultTag() || in_array($tag, $lists['others'], true)) {
                continue;
            }

            $reason = ! array_key_exists($tag, (array) config('languages'))
                ? __('Choose a language.')
                : (in_array($prefix, $lists['prefixes'], true) ? __('Another language already uses :address.', ['address' => '/'.$prefix.'/']) : $this->prefixProblem($prefix));

            if ($reason !== null) {
                $refused[$tag] = $reason;

                continue;
            }

            $lists['others'][] = $tag;
            $lists['prefixes'][$tag] = $prefix;
            $added[] = $tag;
        }

        [$values, $translated] = $this->untranslated($input);

        if ($added !== []) {
            $rows = [];

            foreach ($lists['others'] as $tag) {
                $rows[] = ['name' => $tag, 'value' => $lists['prefixes'][$tag] ?? mb_strtolower($tag)];
            }

            $values = [...$values, 'others' => $lists['others'], 'prefixes' => $rows, 'hidden' => array_values(array_intersect($lists['hidden'], $lists['others']))];
        }

        if ($values !== []) {
            app(SettingsWriter::class)->write(new SettingsWrite(group: SiteLanguages::GROUP, values: $values, actorId: $input->actorId(), origin: 'import'));
        }

        return ['added' => $added, 'refused' => $refused, 'translated' => $translated];
    }

    protected function afterCommit(mixed $result): void
    {
        // After commit: drop the settings memo and the bound SiteLanguages so a
        // later phase of the same import (including a sync-queue test under
        // RefreshDatabase) sees the languages just added.
        Cache::memo()->forget(SettingsRepository::cacheKey(SiteLanguages::GROUP));
        app()->forgetInstance(SiteLanguages::class);
    }

    /**
     * Why the prefix can't be a language's here, or null.
     */
    private function prefixProblem(string $prefix): ?string
    {
        try {
            $this->checkPrefix($prefix, null);
        } catch (ValidationException $refused) {
            return (string) (Arr::first(Arr::flatten($refused->errors())) ?? '');
        }

        return null;
    }

    /**
     * The untranslated lists to write, and the keys kept translated because
     * something of them is in a language already: nothing for a list the
     * input leaves, or that wouldn't change.
     *
     * @return array{0: array<string, list<string>>, 1: list<string>}
     */
    private function untranslated(ImportSiteLanguagesInput $input): array
    {
        $site = app(SiteLanguages::class);
        $values = [];
        $kept = [];

        foreach ([['untranslated_types', 'contents', 'type', $input->untranslatedTypes], ['untranslated_taxonomies', 'terms', 'taxonomy', $input->untranslatedTaxonomies]] as [$key, $table, $column, $chosen]) {
            if ($chosen === null) {
                continue;
            }

            $now = array_values(array_unique(array_filter($chosen, is_string(...))));
            $list = [];

            foreach ($now as $name) {
                $translatedNow = $key === 'untranslated_types' ? $site->translates($name) : $site->translatesTaxonomy($name);
                $inUse = $translatedNow && DB::table($table)->where($column, $name)->where(fn ($query) => $query->whereNotNull('language')->orWhereNotNull('translation_group'))->exists();

                if ($inUse) {
                    $kept[] = $name;

                    continue;
                }

                $list[] = $name;
            }

            sort($list);
            $current = array_values(array_unique(app(SettingsReader::class)->stringList(SiteLanguages::GROUP, $key)));
            sort($current);

            if ($list !== $current) {
                $values[$key] = $list;
            }
        }

        return [$values, $kept];
    }
}

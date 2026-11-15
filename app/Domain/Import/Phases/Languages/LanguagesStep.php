<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Languages;

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Operations\ClearRunMessages;
use App\Domain\Import\Operations\ImportSiteLanguages;
use App\Domain\Import\Operations\Input\ClearRunMessagesInput;
use App\Domain\Import\Operations\Input\ImportSiteLanguagesInput;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Source\SourceReader;
use App\Domain\Import\Translations\ImportedLanguages;
use App\Domain\Import\Translations\PolylangLanguages;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TaxonomyRegistry;

/**
 * Before anyone or anything is written but after the default language
 * (I-3446): the old site's Polylang languages become the site's, in
 * Polylang's order, each with Polylang's slug as its address prefix and
 * shown to readers, and the types and taxonomies Polylang translated are
 * the ones translated here, so every item and term is created in its
 * language at the address it had. A site without Polylang changes nothing.
 */
final readonly class LanguagesStep implements StepPhase
{
    public function __construct(private ImportedLanguages $imported) {}

    public function key(): string
    {
        return 'languages';
    }

    public function order(): int
    {
        return 150;
    }

    public function label(): string
    {
        return 'Languages';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
    {
        app(ClearRunMessages::class)->execute(new ClearRunMessagesInput($context->runId, 'languages.', $context->actorId()));

        $languages = $this->imported->for($context->sourceId);

        if (! $languages->active) {
            return StepResult::done();
        }

        $messages = [];

        if (in_array($languages->mode(), [PolylangLanguages::SUBDOMAIN, PolylangLanguages::DOMAIN], true)) {
            $messages[] = new ImportMessage('languages.per-host', __('Polylang on the old site gave each language a subdomain or a domain of its own. Teme gives each language a first part of the address instead, such as /pt/: add redirects from the old hosts to this site.'));
        }

        if ($languages->defaultTag() === null) {
            $default = $languages->language($languages->default);
            $messages[] = new ImportMessage('languages.default-unknown', __('The old site’s default language, :name (:locale), isn’t one Teme offers yet, so Teme kept its own default.', ['name' => $default['name'] ?? $languages->default, 'locale' => $default['locale'] ?? '']));
        }

        $site = app(SiteLanguages::class);
        $adding = [];
        $named = [];

        foreach ($languages->languages as $language) {
            if ($language['slug'] === $languages->default) {
                continue;
            }

            $words = ['name' => $language['name'] !== '' ? $language['name'] : $language['slug'], 'locale' => $language['locale'], 'prefix' => $language['slug']];

            if ($language['language'] === null) {
                $messages[] = new ImportMessage('languages.not-offered', __('The old site’s :name (:locale) isn’t a language Teme offers yet, so it wasn’t added, and its posts, pages and terms weren’t imported.', $words), $words);

                continue;
            }

            $words['language'] = $site->label($language['language']);
            $named[$language['language']] = $words;

            if ($language['language'] === $site->defaultTag()) {
                $messages[] = new ImportMessage('languages.is-default', __('The old site’s :name (:locale) is :language, this site’s default language, so it wasn’t added again.', $words), $words);
            } elseif ($site->has($language['language']) && $site->prefix($language['language']) !== $language['slug']) {
                $messages[] = new ImportMessage('languages.prefix-kept', __(':language is already one of this site’s languages, under another first part of the address, so its posts and pages don’t answer at /:prefix/. Change its prefix in Settings › Languages.', $words), $words);
            } elseif (! $site->has($language['language'])) {
                $adding[] = ['language' => $language['language'], 'prefix' => $language['slug']];
            }
        }

        [$types, $taxonomies] = self::untranslated($languages);
        $written = app(ImportSiteLanguages::class)->execute(new ImportSiteLanguagesInput($adding, $types, $taxonomies, $context->actorId()));

        foreach ($written['refused'] as $tag => $reason) {
            $words = [...$named[$tag], 'reason' => $reason];
            $messages[] = new ImportMessage('languages.refused', __('Teme couldn’t add :language at /:prefix/: :reason Its posts, pages and terms weren’t imported.', $words), $words);
        }

        if ($written['translated'] !== []) {
            $listed = implode(', ', $written['translated']);
            $messages[] = new ImportMessage('languages.still-translated', __('The old site didn’t translate :listed, but some of them here are in a language already, so they stay translated.', ['listed' => $listed]), ['keys' => $written['translated']]);
        }

        return StepResult::done($messages);
    }

    /**
     * The types and the taxonomies that won't be translated here: those
     * the import brings that Polylang didn't translate, and those it
     * doesn't bring that weren't translated before.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function untranslated(PolylangLanguages $languages): array
    {
        $site = app(SiteLanguages::class);
        $types = [];
        $taxonomies = [];

        foreach (app(ContentTypeRegistry::class)->all() as $type) {
            $translated = $type->wordpress !== null ? $languages->translatesType($type->wordpress) : $site->translates($type->key);

            if (! $translated) {
                $types[] = $type->key;
            }
        }

        foreach (app(TaxonomyRegistry::class)->all() as $taxonomy) {
            $translated = $taxonomy->wordpress !== null ? $languages->translatesTaxonomy($taxonomy->wordpress) : $site->translatesTaxonomy($taxonomy->key);

            if (! $translated) {
                $taxonomies[] = $taxonomy->key;
            }
        }

        return [$types, $taxonomies];
    }
}

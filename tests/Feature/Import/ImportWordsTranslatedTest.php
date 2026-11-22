<?php

declare(strict_types=1);

use App\Domain\Import\Convert\ConversionReport;
use App\Domain\Import\Planning\SiteAnalysis;
use App\Domain\Import\Source\SourceRefused;

/**
 * Words the site's language has a translation for, as a language file holds them.
 *
 * @param  array<string, string>  $lines
 */
function iwtTranslate(array $lines): void
{
    app('translator')->setLoaded(['*' => ['*' => ['de' => $lines]]]);
    app()->setLocale('de');
}

it('says a refusal to reach the site, or a companion too old for its languages, in the site’s language', function (): void {
    iwtTranslate([
        'Teme couldn’t reach the site. Check the address, and that the site is up.' => 'Teme hat die Website nicht erreicht.',
        'Teme couldn’t reach the site: :why. Check the address, and that the site is up.' => 'Teme hat die Website nicht erreicht: :why.',
        'it didn’t answer in time' => 'sie antwortete nicht rechtzeitig',
        'The site report says Polylang is on, with :count languages, and this site’s Teme Companion plugin is too old to bring languages across: everything would come in one language. Update the plugin to :version or later, then choose Analyze again.' => 'Polylang hat :count Sprachen; Companion :version nötig.',
    ]);

    expect(SourceRefused::unreachable()->sentence())->toBe('Teme hat die Website nicht erreicht.')
        ->and(SourceRefused::unreachable(__('it didn’t answer in time'))->sentence())->toBe('Teme hat die Website nicht erreicht: sie antwortete nicht rechtzeitig.')
        ->and(SourceRefused::languagesTooOld(5, '0.3.0')->sentence())->toBe('Polylang hat 5 Sprachen; Companion 0.3.0 nötig.');
});

it('says what it left out of a table of contents, and the languages the report counts, in the site’s language', function (): void {
    iwtTranslate([
        'Left out :count tables of contents (:name): Teme and its theme don’t draw them; a table-of-contents plugin can.' => ':count Inhaltsverzeichnisse (:name) ausgelassen.',
        'Languages' => 'Sprachen',
    ]);
    $report = new ConversionReport;
    $report->count('block.contents-list', 'yoast/table-of-contents', null, 2);
    $site = tiReport(['multilingual' => ['wpml' => null, 'polylang' => ['version' => '3.7.4', 'languages' => 5], 'translatepress' => null, 'weglot' => null]])['report'];
    $comes = collect(SiteAnalysis::fromReport($site)->toArray()['comes'])->keyBy('key');

    expect($report->messages()[0]->message)->toBe('2 Inhaltsverzeichnisse (yoast/table-of-contents) ausgelassen.')
        ->and($comes['languages']['label'])->toBe('Sprachen');
});

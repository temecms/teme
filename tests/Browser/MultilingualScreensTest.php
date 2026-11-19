<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\TranslateContentInput;
use App\Domain\Contents\Operations\TranslateContent;
use App\Domain\Languages\SiteLanguages;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\Languages\Languages;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server (TwoEditorsTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->site = LanguageSite::build();

    // Warm the screens before the browser's first visit (I-638).
    $this->actingAs($this->site->admin)->get('/admin/settings/languages')->assertOk();
    $this->actingAs($this->site->editor)->get("/admin/content/{$this->site->alone}/edit")->assertOk();
});

/** Presses a key on whatever has focus, as a person at the keyboard does. */
function mlsPress(mixed $page, string $key): mixed
{
    $page->page()->locator(':focus')->press($key);

    return $page;
}

/** Types into whatever has focus. */
function mlsType(mixed $page, string $text): mixed
{
    $page->page()->locator(':focus')->type($text);

    return $page;
}

/** Presses Tab until the focused element matches $selector, at most $most times. */
function mlsTabTo(mixed $page, string $selector, int $most = 12): mixed
{
    $quoted = json_encode($selector, JSON_THROW_ON_ERROR);

    for ($i = 0; $i < $most; $i++) {
        if (cmAnswer($page, "() => document.activeElement?.matches({$quoted}) === true", 'mlsTabTo')) {
            return $page;
        }

        mlsPress($page, 'Tab');
    }

    return cmWaitUntil($page, "document.activeElement?.matches({$quoted}) === true");
}

/** The languages table's rows' tags, in order, joined by commas. */
function mlsRows(): string
{
    return "Array.from(document.querySelectorAll('[data-test=\"languages-table\"] tr[data-language]')).map((row) => row.dataset.language).join(',')";
}

/** Opens Settings › Languages as the administrator. */
function mlsLanguages(mixed $test, int $width = 1280): mixed
{
    $page = cmNavigate(cmSignIn($test->site->admin)->resize($width, 900), '/admin/settings/languages');

    return cmWaitUntil($page, "document.querySelector('[data-test=\"languages-table\"] tr[data-language]') !== null");
}

/** Opens an item's editor as the editor, and waits for the Translations section. */
function mlsEditor(mixed $test, string $id, int $width = 1440): mixed
{
    $page = cmNavigate(cmSignIn($test->site->editor)->resize($width, 900), "/admin/content/{$id}/edit");

    // On a phone the rail is a sheet, opened from the top bar (P3).
    if ($width < 1024) {
        cmWaitUntil($page, "document.querySelector('.teme-prose') !== null");
        $page->click('[data-test="rail-toggle"]');
        shellSettled($page, '[data-slot="sheet-content"]');
    }

    return cmWaitUntil($page, "document.querySelector('[data-test=\"translations-section\"]') !== null");
}

/** A script for the words in one language's row of the Translations section. */
function mlsRow(string $tag): string
{
    return "(document.querySelector('[data-test=\"translation-row\"][data-language=\"{$tag}\"]')?.innerText ?? '')";
}

/** A Translations row's "⋯", opened, its menu settled. */
function mlsVersionMenu(mixed $page, string $tag): mixed
{
    $page->click("[data-test=\"translation-row\"][data-language=\"{$tag}\"] [data-test=\"translation-menu\"]");

    return shellSettled($page, '[role="menu"]');
}

it('draws the default language as a definition list, and every other row with Change and Remove', function (): void {
    $page = mlsLanguages($this);

    $page->assertScript(mlsRows()." === 'fr,es'")
        ->assertScript('document.querySelector(\'tr[data-language="en-GB"]\') === null')
        ->assertScript('document.querySelector(\'[data-test="language-default"]\').innerText.includes("Default")')
        ->assertScript('document.querySelector(\'#default-language\').innerText.includes("Change it in General")')
        ->assertScript('document.querySelectorAll(\'tr[data-language="fr"] [data-test="language-edit"], tr[data-language="fr"] [data-test="language-remove"]\').length === 2')
        ->assertScript('document.querySelector(\'tr[data-language="es"]\').innerText.includes("Hidden")')
        ->assertScript('document.querySelector(\'tr[data-language="fr"]\').innerText.includes("/fr/")')
        // The actions sit inside the table at 1280, not cut off by its edge.
        ->assertScript('document.querySelector(\'tr[data-language="fr"] [data-test="language-remove"]\').getBoundingClientRect().right <= document.querySelector(\'[data-test="languages-table"]\').getBoundingClientRect().right');
    cmNoJavaScriptErrors($page);
});

it('adds a language with the keyboard alone', function (): void {
    $page = mlsLanguages($this);

    $page->page()->locator('[data-test="language-add"]')->focus();
    mlsPress($page, 'Enter');
    shellSettled($page, '[data-test="language-sheet"]');

    mlsTabTo($page, '#language-tag');
    mlsPress($page, 'Enter');
    shellSettled($page, '[data-slot="popover-content"]');
    cmWaitUntil($page, "document.activeElement?.getAttribute('role') === 'combobox'");
    mlsType($page, 'german');
    cmWaitUntil($page, "Array.from(document.querySelectorAll('[role=\"option\"]')).some((option) => option.innerText.includes('German'))");
    mlsPress($page, 'ArrowDown');
    mlsPress($page, 'Enter');
    cmWaitUntil($page, "document.querySelector('[data-slot=\"popover-content\"]') === null && document.getElementById('language-prefix').value === 'de'");

    mlsTabTo($page, '[data-test="language-save"]');
    mlsPress($page, 'Enter');
    cmWaitUntil($page, mlsRows()." === 'fr,es,de'");

    app()->forgetInstance(SiteLanguages::class);
    app()->forgetScopedInstances();

    expect(app(SiteLanguages::class)->others())->toBe(['fr', 'es', 'de']);
});

it('changes a language\'s prefix and hides it from readers in its sheet', function (): void {
    $page = mlsLanguages($this);

    $page->click('tr[data-language="fr"] [data-test="language-edit"]');
    shellSettled($page, '[data-test="language-sheet"]');
    $page->fill('#language-prefix', 'francais')
        ->click('#language-shown')
        ->click('[data-test="language-save"]');
    cmWaitUntil($page, "document.querySelector('tr[data-language=\"fr\"]').innerText.includes('/francais/') && document.querySelector('tr[data-language=\"fr\"]').innerText.includes('Hidden')");

    app()->forgetInstance(SiteLanguages::class);
    app()->forgetScopedInstances();
    $languages = app(SiteLanguages::class);

    expect($languages->prefix('fr'))->toBe('francais')
        ->and($languages->isShown('fr'))->toBeFalse();
});

it('moves a language up and down with its arrows', function (): void {
    $page = mlsLanguages($this);

    $page->assertScript('document.querySelector(\'tr[data-language="fr"] [data-test="language-move-up"]\').disabled === true')
        ->click('tr[data-language="es"] [data-test="language-move-up"]');
    cmWaitUntil($page, mlsRows()." === 'es,fr'");

    $page->click('tr[data-language="es"] [data-test="language-move-down"]');
    cmWaitUntil($page, mlsRows()." === 'fr,es'");

    app()->forgetInstance(SiteLanguages::class);
    app()->forgetScopedInstances();
    $languages = app(SiteLanguages::class);

    expect($languages->others())->toBe(['fr', 'es']);
});

it('removes an empty language after asking, and shows the refusal for one that holds content', function (): void {
    Languages::set(['fr', 'es', 'de'], hidden: ['es']);
    $page = mlsLanguages($this);

    $page->click('tr[data-language="de"] [data-test="language-remove"]');
    shellSettled($page, '[data-test="language-remove-dialog"]');
    $page->click('[data-test="language-remove-confirm"]');
    cmWaitUntil($page, mlsRows()." === 'fr,es'");

    $page->click('tr[data-language="fr"] [data-test="language-remove"]');
    shellSettled($page, '[data-test="language-remove-dialog"]');
    $page->click('[data-test="language-remove-confirm"]');
    cmWaitUntil($page, "(document.querySelector('[data-test=\"languages-refusal\"]')?.innerText ?? '').includes('are in French. Move them to another language, or delete them, first.')");

    $page->assertScript(mlsRows()." === 'fr,es'");
});

it('passes axe on Settings › Languages at 1280 and 375, in both schemes, with nothing scrolling sideways', function (string $scheme, int $width): void {
    lscrPrefer($this->site->admin, ['appearance.mode' => $scheme]);
    $page = mlsLanguages($this, $width);

    expect(cmAxeViolations($page))->toBe([])
        ->and($page->script('() => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth)'))->toBe(0);
    cmNoJavaScriptErrors($page);
})->with(['light', 'dark'])->with([1280, 375]);

it('lists every language in the editor\'s Translations section, under Publish, with its summary', function (): void {
    $page = mlsEditor($this, $this->site->post);

    $page->assertScript("document.querySelector('[data-test=\"translations-summary\"]').innerText.includes('3 of 3')")
        ->assertScript(mlsRow('en-GB').".includes('Editing')")
        ->assertScript(mlsRow('fr').".includes('Published')")
        ->assertScript("document.querySelector('[data-test=\"translation-row\"][data-language=\"fr\"] [data-test=\"translation-open\"]').getAttribute('href').endsWith('/admin/content/{$this->site->postFr}/edit')");
    cmNoJavaScriptErrors($page);
});

it('makes a version with Translate, after asking, and opens it', function (): void {
    $page = mlsEditor($this, $this->site->alone);

    $page->assertScript(mlsRow('fr').".includes('Not translated')")
        ->click('[data-test="translation-row"][data-language="fr"] [data-test="translation-translate"]');
    shellSettled($page, '[data-test="translations-dialog"]');
    $page->click('[data-test="translations-confirm"]');

    $made = null;
    cmWaitUntil($page, "location.pathname !== '/admin/content/{$this->site->alone}/edit' && document.querySelector('[data-test=\"translations-section\"]') !== null");
    $made = (string) DB::table('contents')->where('language', 'fr')->where('title', 'Alone')->value('id');

    expect($made)->not->toBe('')
        ->and($page->script('() => location.pathname'))->toBe("/admin/content/{$made}/edit");
});

it('shows a version out of date in its pill, and clears it with Mark up to date', function (): void {
    $source = Content::query()->findOrFail($this->site->alone);
    app(TranslateContent::class)->execute(new TranslateContentInput($this->site->alone, 'fr', (string) $this->site->editor->id));
    $this->travel(1)->minutes();
    docSave($source, $this->site->editor, docWith(docText('Alone, rewritten')));
    listsPublish($source, $this->site->editor);
    $this->travelBack();

    $page = mlsEditor($this, $this->site->alone);
    $page->assertScript(mlsRow('fr').".includes('out of date')")
        ->assertScript("document.querySelector('[data-test=\"translations-summary\"]').innerText.includes('1 out of date')");

    mlsVersionMenu($page, 'fr');
    $page->click('[data-test="translation-up-to-date"]');
    cmWaitUntil($page, '!'.mlsRow('fr').".includes('out of date')");
});

it('shows the refusal\'s sentence when a version changed since the page opened', function (): void {
    $source = Content::query()->findOrFail($this->site->alone);
    $french = app(TranslateContent::class)->execute(new TranslateContentInput($this->site->alone, 'fr', (string) $this->site->editor->id))->content;
    $this->travel(1)->minutes();
    docSave($source, $this->site->editor, docWith(docText('Alone, rewritten')));
    listsPublish($source, $this->site->editor);
    $this->travelBack();

    $page = mlsEditor($this, $this->site->alone);
    docSave(Content::query()->findOrFail($french->id), $this->site->editor, docWith(docText('Seul, réécrit')));

    mlsVersionMenu($page, 'fr');
    $page->click('[data-test="translation-up-to-date"]');
    cmWaitUntil($page, "(document.querySelector('[data-test=\"translations-refusal\"]')?.innerText ?? '').includes('Reload and try again.')");
});

it('unlinks a version after asking', function (): void {
    $page = mlsEditor($this, $this->site->post);

    mlsVersionMenu($page, 'es');
    $page->click('[data-test="translation-unlink"]');
    shellSettled($page, '[data-test="translations-dialog"]');
    $page->click('[data-test="translations-confirm"]');
    cmWaitUntil($page, mlsRow('es').".includes('Not translated')");

    expect(DB::table('contents')->where('id', $this->site->postEs)->value('translation_group'))->not->toBe($this->site->postGroup);
});

it('links another post as a version, found by its title', function (): void {
    $seul = LanguageSite::version($this->site->editor, 'Seul', 'fr', null, [], publish: true);
    $page = mlsEditor($this, $this->site->alone);

    mlsVersionMenu($page, 'fr');
    $page->click('[data-test="translation-link"]');
    shellSettled($page, '[data-test="translations-dialog"]');
    $page->click('#translation-link');
    shellSettled($page, '[data-slot="popover-content"]');
    cmWaitUntil($page, "document.activeElement?.getAttribute('role') === 'combobox'");
    mlsType($page, 'seu');
    cmWaitUntil($page, "Array.from(document.querySelectorAll('[role=\"option\"]')).some((option) => option.innerText.includes('Seul'))");
    mlsPress($page, 'ArrowDown');
    mlsPress($page, 'Enter');
    cmWaitUntil($page, "document.querySelector('[data-slot=\"popover-content\"]') === null");
    $page->click('[data-test="translation-link-confirm"]');
    cmWaitUntil($page, "document.querySelector('[data-test=\"translation-row\"][data-language=\"fr\"] [data-test=\"translation-open\"]') !== null");

    expect(DB::table('contents')->where('id', $seul)->value('translation_group'))
        ->toBe(DB::table('contents')->where('id', $this->site->alone)->value('translation_group'))
        ->not->toBeNull();
});

it('opens a version\'s "⋯" from the keyboard, moves with the arrows, and Escape returns focus', function (): void {
    $page = mlsEditor($this, $this->site->post);
    $trigger = '[data-test="translation-row"][data-language="fr"] [data-test="translation-menu"]';

    $page->page()->locator($trigger)->focus();
    mlsPress($page, 'Enter');
    shellSettled($page, '[role="menu"]');
    cmWaitUntil($page, "document.activeElement?.getAttribute('role') === 'menuitem'");
    $first = cmAnswer($page, '() => document.activeElement.innerText', 'first item');
    mlsPress($page, 'ArrowDown');
    cmWaitUntil($page, 'document.activeElement?.getAttribute(\'role\') === \'menuitem\' && document.activeElement.innerText !== '.json_encode($first, JSON_THROW_ON_ERROR));
    mlsPress($page, 'Escape');
    cmWaitUntil($page, "document.querySelector('[role=\"menu\"]') === null && document.activeElement === document.querySelector('{$trigger}')");
});

it('switches between versions from the language chip', function (): void {
    $page = mlsEditor($this, $this->site->post);

    $page->assertScript("document.querySelector('[data-test=\"language-chip\"]').innerText.includes('EN')")
        ->click('[data-test="language-chip"]');
    shellSettled($page, '[role="menu"]');
    $page->click('[role="menu"] [data-language="fr"]');
    cmWaitUntil($page, "location.pathname === '/admin/content/{$this->site->postFr}/edit' && document.querySelector('[data-test=\"language-chip\"]')?.innerText.includes('FR')");
});

it('passes axe on the editor\'s Translations section at 1440 and 375, in both schemes', function (string $scheme, int $width): void {
    lscrPrefer($this->site->editor, ['appearance.mode' => $scheme]);
    $page = mlsEditor($this, $this->site->post, $width);
    $page->script('() => document.querySelector("[data-test=translations-section]").scrollIntoView()');

    expect(cmAxeViolations($page))->toBe([])
        ->and($page->script('() => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth)'))->toBe(0);
    cmNoJavaScriptErrors($page);
})->with(['light', 'dark'])->with([1440, 375]);

it('saves Igbo\'s tagline in other languages, keeps it on reload, and shows the default\'s again once cleared', function (): void {
    Languages::set(['ig', 'fr']);
    $this->actingAs($this->site->admin)->get('/admin/settings/general')->assertOk();
    $field = '#words-ig-tagline';

    $page = cmNavigate(cmSignIn($this->site->admin)->resize(1280, 900), '/admin/settings/general');
    cmWaitUntil($page, "document.querySelector('{$field}') !== null");
    $placeholder = cmAnswer($page, "() => document.querySelector('{$field}').placeholder", 'placeholder');

    $page->fill($field, 'Ihe gbasara osisi')->click('[data-test="words-save"]');
    cmWaitUntil($page, "document.querySelector('[data-test=\"words-save\"]').getAttribute('aria-busy') !== 'true' && document.querySelector('[data-test=\"words-refusal\"]') === null");
    cmWaitUntil($page, "(document.querySelector('[data-sonner-toaster]')?.innerText ?? '').includes('Saved the words in other languages.')");

    cmNavigate($page, '/admin/settings/general');
    cmWaitUntil($page, "document.querySelector('{$field}')?.value === 'Ihe gbasara osisi'");

    $page->fill($field, '')->click('[data-test="words-save"]');
    cmWaitUntil($page, "(document.querySelector('[data-sonner-toaster]')?.innerText ?? '').includes('Saved the words in other languages.')");
    cmNavigate($page, '/admin/settings/general');
    cmWaitUntil($page, "document.querySelector('{$field}')?.value === ''");

    expect(cmAnswer($page, "() => document.querySelector('{$field}').placeholder", 'placeholder'))->toBe($placeholder);
    cmNoJavaScriptErrors($page);
});

it('passes axe on General\'s words in other languages at 1280 and 375, in both schemes, with nothing scrolling sideways', function (string $scheme, int $width): void {
    lscrPrefer($this->site->admin, ['appearance.mode' => $scheme]);
    $this->actingAs($this->site->admin)->get('/admin/settings/general')->assertOk();

    $page = cmNavigate(cmSignIn($this->site->admin)->resize($width, 900), '/admin/settings/general');
    cmWaitUntil($page, "document.getElementById('words-in-other-languages') !== null");
    $page->script('() => document.getElementById("words-in-other-languages").scrollIntoView()');

    expect(cmAxeViolations($page))->toBe([])
        ->and($page->script('() => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth)'))->toBe(0);
    cmNoJavaScriptErrors($page);
})->with(['light', 'dark'])->with([1280, 375]);

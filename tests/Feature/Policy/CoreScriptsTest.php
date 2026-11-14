<?php

declare(strict_types=1);

use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use App\Domain\Site\Policy\PagePolicy;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Policy\PolicyHeaders;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [], 'app.url' => 'http://localhost']);
    themeActivate('default');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->item = cmItem($this->editor);
    $this->about = addrPublished($this->editor, 'About', 'page');
    $nav = navCreate($this->editor, 'Primary');
    navSave($this->editor, $nav, [['kind' => 'heading', 'label' => 'Visit', 'children' => [['kind' => 'content', 'target_id' => $this->about->id]]]]);
    app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('default', 'primary', $nav->id, (string) $this->editor->id));

    // Each inline script core wrote, by its opening tag's marker, and its text as the page carries it.
    $this->scripts = function (string $html): array {
        $found = [];

        foreach (dtDom($html)->query('//script[not(@src)][not(@type="application/ld+json")]') as $script) {
            $marker = $script->getAttribute('data-teme') ?: $script->getAttribute('type');
            $found[$marker] = $script->textContent;
        }

        return $found;
    };
});

it("allows each of core's inline scripts by the hash of exactly what the page carries", function (): void {
    $response = addrGet('/harbour-news/')->assertOk();
    $scripts = ($this->scripts)((string) $response->getContent());
    $hashes = PolicyHeaders::hashes(PolicyHeaders::of($response));

    expect(array_keys($scripts))->toEqualCanonicalizing(['session', 'menus', 'comments', 'speculationrules']);

    foreach ($scripts as $text) {
        expect($text)->not->toBe('')
            ->and(PolicyHeaders::sources(PolicyHeaders::of($response), 'script-src'))->toContain(PagePolicy::hash($text));
    }

    expect($hashes)->toEqualCanonicalizing(array_values(array_map(PagePolicy::hash(...), $scripts)));
});

it("keeps the tags core's scripts are found by", function (): void {
    $html = (string) addrGet('/harbour-news/')->assertOk()->getContent();

    expect($html)->toContain('<script data-teme="session">')
        ->toContain('<script data-teme="menus">')
        ->toContain('<script data-teme="comments">')
        ->toContain('<script type="speculationrules">');
});

it('allows no comments script on a page without one', function (): void {
    $response = addrGet('/about/')->assertOk();
    $scripts = ($this->scripts)((string) $response->getContent());

    expect(array_keys($scripts))->toEqualCanonicalizing(['session', 'menus', 'speculationrules'])
        ->and(PolicyHeaders::hashes(PolicyHeaders::of($response)))->toHaveCount(3)
        ->toEqualCanonicalizing(array_values(array_map(PagePolicy::hash(...), $scripts)));
});

it('hashes the comments script per language as printed', function (): void {
    $english = addrGet('/harbour-news/')->assertOk();
    $englishText = ($this->scripts)((string) $english->getContent())['comments'];

    // A public page sets the locale to its own language (I-3360), so the words are given in that one.
    app('translator')->addLines(['*.Leave a comment' => 'Laissez un commentaire'], app()->getLocale());

    $other = addrGet('/harbour-news/')->assertOk();
    $otherText = ($this->scripts)((string) $other->getContent())['comments'];

    expect($otherText)->toContain('Laissez un commentaire')
        ->and(PagePolicy::hash($otherText))->not->toBe(PagePolicy::hash($englishText))
        ->and(PolicyHeaders::hashes(PolicyHeaders::of($other)))->toContain(PagePolicy::hash($otherText))
        ->not->toContain(PagePolicy::hash($englishText));
});

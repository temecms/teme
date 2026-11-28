<?php

declare(strict_types=1);

use App\Domain\Cache\SignedInMarker;
use App\Domain\Site\ViewModels\CommentFormView;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->render = fn (CommentFormView $form): DOMXPath => dtDom('<meta charset="utf-8">'.Blade::render('<x-teme::comment-form :form="$form" />', ['form' => $form]));
    $this->form = fn (array $overrides = []): CommentFormView => new CommentFormView(...array_replace([
        'action' => '/_teme/comments',
        'path' => '/harbour-news/',
        'parentId' => null,
        'parentName' => null,
        'askWebsite' => true,
        'signedInOnly' => false,
        'keepIpDays' => 60,
    ], $overrides));
});

it('draws core\'s fields in the form\'s order, its hidden hooks, the honeypot with them, and the privacy line', function (): void {
    $dom = ($this->render)(($this->form)());
    $names = array_map(fn (DOMElement $field): string => $field->getAttribute('name'), iterator_to_array($dom->query('//form//input|//form//textarea') ?: []));

    expect($names)->toBe(['path', 'parent', 'token', '_token', 'subject', 'text', 'name', 'email', 'url', 'remember'])
        ->and($dom->query('//form[@method="post"][@action="/_teme/comments"][@novalidate][@data-teme-form]')->length)->toBe(1)
        ->and($dom->query('//input[@name="path"][@value="/harbour-news/"]')->length)->toBe(1)
        ->and($dom->query('//div[@aria-hidden="true"][contains(@class, "teme-hp")]/input[@name="subject"][@tabindex="-1"][@autocomplete="off"]')->length)->toBe(1)
        ->and($dom->query('//*[@data-teme-remember][@hidden] | //*[@data-teme-as][@hidden] | //*[@data-teme-replying][@hidden] | //*[@data-teme-cancel][@hidden]')->length)->toBe(4)
        ->and($dom->query('//*[@role="status"][@data-teme-status]')->length)->toBe(1)
        ->and(trim((string) $dom->query('//*[contains(@class, "teme-privacy")]')->item(0)?->textContent))
        ->toBe('Your name and website are shown with your comment; your email address never is. To fight spam, we keep your IP address and browser details for 60 days, then delete them.');
});

it('asks no website while the site doesn\'t, and says so in the privacy line (I-596)', function (): void {
    $dom = ($this->render)(($this->form)(['askWebsite' => false]));

    expect($dom->query('//input[@name="url"]')->length)->toBe(0)
        ->and(trim((string) $dom->query('//*[@data-teme-remember]//label')->item(0)?->textContent))->toBe('Save my name and email address in this browser for next time')
        ->and(trim((string) $dom->query('//*[contains(@class, "teme-privacy")]')->item(0)?->textContent))->toStartWith('Your name is shown with your comment; your email address never is.');
});

it('labels a reply\'s form, and leaves the heading to its page when it stands on one of its own', function (): void {
    $dom = ($this->render)(($this->form)(['parentId' => '01k6aaaaaaaaaaaaaaaaaaaaaa', 'parentName' => 'Bo Reader', 'standalone' => true]));

    expect($dom->query('//*[@id="respond-h"]')->length)->toBe(0)
        ->and($dom->query('//form[@aria-labelledby="page-h"]')->length)->toBe(1)
        ->and($dom->query('//input[@name="parent"][@value="01k6aaaaaaaaaaaaaaaaaaaaaa"]')->length)->toBe(1)
        ->and(trim((string) $dom->query('//label[@for="c-text"]')->item(0)?->textContent))->toBe('Your reply')
        ->and(trim((string) $dom->query('//button[@type="submit"]')->item(0)?->textContent))->toBe('Post reply')
        ->and($dom->query('//*[@data-teme-cancel]')->length)->toBe(0);
});

it('draws what was wrong first, each field marked and described, the reader\'s words kept (D14)', function (): void {
    $dom = ($this->render)(($this->form)([
        'standalone' => true,
        'values' => ['text' => 'Hello <b>', 'name' => '', 'email' => 'not an address', 'url' => ''],
        'errors' => [['field' => 'email', 'target' => 'c-email', 'message' => 'Enter an email address like name@example.com.']],
    ]));

    expect(trim((string) $dom->query('//*[@id="respond-errors"][@autofocus]//h2')->item(0)?->textContent))->toBe('There’s a problem')
        ->and($dom->query('//*[@id="respond-errors"]//a[@href="#c-email"]')->length)->toBe(1)
        ->and($dom->query('//input[@id="c-email"][@aria-invalid="true"][@aria-describedby="c-email-hint c-email-error"][@value="not an address"]')->length)->toBe(1)
        ->and(trim((string) $dom->query('//*[@id="c-email-error"]')->item(0)?->textContent))->toBe('Error: Enter an email address like name@example.com.')
        ->and((string) $dom->query('//textarea[@id="c-text"]')->item(0)?->textContent)->toBe('Hello <b>');
});

it('draws a line in the form\'s place where only signed-in people may comment, and the form for a browser signed in (D16)', function (): void {
    $dom = ($this->render)(($this->form)(['signedInOnly' => true]));

    expect($dom->query('//form')->length)->toBe(0)
        ->and(trim((string) $dom->query('//*[contains(@class, "teme-signed-in-only")]')->item(0)?->textContent))->toBe('Only people signed in to this site can comment here.');

    request()->cookies->set(SignedInMarker::COOKIE, '1');

    expect(($this->render)(($this->form)(['signedInOnly' => true]))->query('//form')->length)->toBe(1);
});

it('keeps the honeypot out of sight on every page, in core\'s own layer, whatever the theme (D8)', function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    cmItem(User::factory()->create()->assignRole('author'));

    expect((string) addrGet('/harbour-news/')->getContent())
        ->toContain('.teme-hp,.teme-sr-only{position:absolute!important;inline-size:1px!important;block-size:1px!important;overflow:hidden!important;clip-path:inset(50%)!important;white-space:nowrap!important}');
});

it('keeps the parts core\'s script shows hidden until it does, whatever the theme sets (1.8b′, B-363)', function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    cmItem(User::factory()->create()->assignRole('author'));

    // A theme's own display (a button's inline-flex, a section's grid) must not undo the hidden attribute.
    expect((string) addrGet('/harbour-news/')->getContent())->toContain('.teme-respond [hidden]{display:none!important}');
});

it('keeps what core\'s script hides in the thread hidden, and the Edit link a target at least 24 pixels, whatever the theme sets (1.8c″, B-363, D9)', function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    cmItem(User::factory()->create()->assignRole('author'));

    expect((string) addrGet('/harbour-news/')->getContent())
        ->toContain('[data-teme-actions][hidden],[data-teme-empty][hidden]{display:none!important}')
        ->toContain('.teme-comment-edit{display:inline-flex;align-items:center;min-block-size:1.5rem;min-inline-size:1.5rem}');
});

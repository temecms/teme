<?php

declare(strict_types=1);

use App\Domain\SiteCode\Operations\Input\SaveSiteCodeInput;
use App\Domain\SiteCode\Operations\SaveSiteCode;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Pest\Browser\Playwright\Playwright;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs core's built styles.
    $this->withVite();
    $this->seed(RoleSeeder::class);
    // The test sets the mode for itself: nothing in config enforces by default (I-3375).
    config(['teme.theme_paths' => [], 'teme.public_csp' => 'enforce']);
    themeActivate('default');

    // Each request starts afresh, as on a real server (CommentsScriptTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->admin = User::factory()->create()->assignRole('administrator');
    cmItem($this->admin);

    // Site code that loads one script written in it, and adds another whose address isn't. Both
    // hosts are reserved never to resolve (RFC 6761), so no request leaves the machine; the first
    // is async, so nothing the page's parsing waits for depends on it.
    $this->code = '<script async src="https://found.example.invalid/f.js"></script>'
        ."<script>document.head.append(Object.assign(document.createElement('script'), {src: 'https://' + 'cdn.example.invalid/late.js'}));</script>";
    $this->add = fn (array $origins): array => scElevated(fn (): array => app(SaveSiteCode::class)->execute(
        new SaveSiteCodeInput(null, 'Chat widget', 'body-end', $this->code, true, (string) $this->admin->id, $origins),
    ));

    // The browser's own record of what the enforced policy blocked, once the page is parsed (never
    // its load, which waits on the scripts' requests): until $expected reports are in, or the
    // plugin's timeout, then a short settle for any more.
    $this->blocked = function (mixed $page, int $expected): array {
        $timeout = Playwright::timeout();
        cmWaitUntil($page, "document.readyState !== 'loading'");

        return $page->script(<<<JS
            async () => {
                const seen = [];
                const observer = new ReportingObserver((reports) => seen.push(...reports), { types: ['csp-violation'], buffered: true });
                observer.observe();
                const until = Date.now() + {$timeout};
                while (seen.length < {$expected} && Date.now() < until) {
                    await new Promise((resolve) => setTimeout(resolve, 50));
                }
                await new Promise((resolve) => setTimeout(resolve, 300));
                seen.push(...observer.takeRecords());
                observer.disconnect();
                return seen.map((report) => report.body.effectiveDirective + ' ' + report.body.blockedURL);
            }
            JS);
    };

    addrGet('/harbour-news/')->assertOk();
});

it('blocks nothing on a public page under enforce when site code declares the host its script adds', function (): void {
    ($this->add)(['cdn.example.invalid']);

    $response = addrGet('/harbour-news/')->assertOk();
    $page = visit('/harbour-news/');

    expect($response->headers->get('Content-Security-Policy'))->toContain('https://cdn.example.invalid')
        ->and(($this->blocked)($page, 0))->toBe([]);
});

it('blocks the script an undeclared host serves, and nothing else', function (): void {
    ($this->add)([]);

    $page = visit('/harbour-news/');

    expect(($this->blocked)($page, 1))->toBe(['script-src-elem https://cdn.example.invalid/late.js']);
});

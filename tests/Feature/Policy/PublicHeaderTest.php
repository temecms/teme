<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\MaintenanceModeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\Contents\Visibilities;
use Tests\Fixtures\Policy\PolicyHeaders;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [], 'app.url' => 'http://localhost']);
    themeActivate('default');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->item = cmItem($this->author);
});

it('sends the policy report-only by default, with where to report', function (): void {
    $response = addrGet('/harbour-news/')->assertOk();

    expect((string) $response->headers->get('Content-Security-Policy-Report-Only'))->toContain("default-src 'self'")
        ->and($response->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($response->headers->get('Reporting-Endpoints'))->toBe('teme-csp="http://localhost/_teme/csp"');
});

it('sends it enforced in enforce mode', function (): void {
    config(['teme.public_csp' => 'enforce']);
    $response = addrGet('/harbour-news/')->assertOk();

    expect((string) $response->headers->get('Content-Security-Policy'))->toContain("default-src 'self'")
        ->and($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse()
        ->and($response->headers->get('Reporting-Endpoints'))->toBe('teme-csp="http://localhost/_teme/csp"');
});

it('sends neither header when off', function (): void {
    config(['teme.public_csp' => 'off']);
    $response = addrGet('/harbour-news/')->assertOk();

    expect($response->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse()
        ->and($response->headers->has('Reporting-Endpoints'))->toBeFalse();
});

it('reads an unknown mode as report-only', function (): void {
    config(['teme.public_csp' => 'Sometimes']);
    $response = addrGet('/harbour-news/')->assertOk();

    expect((string) $response->headers->get('Content-Security-Policy-Report-Only'))->toContain("default-src 'self'")
        ->and($response->headers->has('Content-Security-Policy'))->toBeFalse();
});

it("carries it on a 404, an error page, the password form's answer, a comment's reply page, the comment form's answer and a preview link's page", function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    Visibilities::change(Visibilities::published($editor, 'Members update', 'zqprotected'), 'protected', $editor, 'Harbour 2024');
    rdSaved('/old-fares', status: 410);
    $comment = cmComment($this->item);
    $draft = lifecycleDraft($this->author, 'Harbour festival');
    $link = histPreview($draft->refresh(), $this->author);

    $post = fn (string $uri, array $fields, array $server = []): TestResponse => TestResponse::fromBaseResponse(
        app(Kernel::class)->handle($request = Request::create($uri, 'POST', $fields, server: ['REMOTE_ADDR' => '203.0.113.9', ...$server])),
        $request,
    );

    $answers = [
        'a 404' => addrGet('/nothing-here/')->assertNotFound(),
        'a 410' => addrGet('/old-fares')->assertStatus(410),
        'the password form' => $post('/_teme/unlock', ['path' => '/members-update/', 'password' => 'wrong'], ['HTTP_SEC_FETCH_SITE' => 'same-origin'])->assertStatus(422),
        'the reply page' => addrGet("/_teme/comment/{$comment->id}/reply")->assertOk(),
        'the comment form' => cmPost($this->item, ['text' => ''])->assertStatus(422),
        'a preview link' => addrGet("/preview/{$link->token}")->assertOk(),
    ];

    foreach ($answers as $case => $response) {
        expect(PolicyHeaders::of($response))->toContain("default-src 'self'")
            ->and(PolicyHeaders::hashes(PolicyHeaders::of($response)))->not->toBe([], $case)
            ->and($response->headers->get('Reporting-Endpoints'))->toBe('teme-csp="http://localhost/_teme/csp"', $case);
    }
});

it('sends no policy on the pages Laravel draws itself: a 500 and maintenance mode\'s 503', function (): void {
    Route::middleware('public')->get('policy-boom', fn () => throw new RuntimeException('Broken on purpose.'));
    app('router')->getRoutes()->refreshNameLookups();

    $failed = addrGet('/policy-boom')->assertStatus(500);

    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
    app()->forgetInstance(MaintenanceModeManager::class);
    app()->maintenanceMode()->activate(['retry' => 60]);

    $down = addrGet('/harbour-news/')->assertStatus(503);

    foreach ([$failed, $down] as $response) {
        expect($response->headers->has('Content-Security-Policy'))->toBeFalse()
            ->and($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse()
            ->and($response->headers->has('Reporting-Endpoints'))->toBeFalse();
    }
});

it("keeps the admin's policy alone on the signed-in preview", function (): void {
    $content = Content::query()->findOrFail($this->item->id);

    $response = $this->actingAs($this->author)->get(route('content.preview', ['content' => $content->id]))->assertOk();

    expect((string) $response->headers->get('Content-Security-Policy'))->toContain("'nonce-")
        ->and($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse()
        ->and($response->headers->has('Reporting-Endpoints'))->toBeFalse();
});

it('sends nothing on a feed, the sitemap or robots.txt', function (): void {
    foreach (['/feed/', '/sitemap.xml', '/robots.txt'] as $uri) {
        $response = addrGet($uri)->assertOk();

        expect($response->headers->has('Content-Security-Policy'))->toBeFalse($uri)
            ->and($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse($uri)
            ->and($response->headers->has('Reporting-Endpoints'))->toBeFalse($uri);
    }
});

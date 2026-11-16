<?php

declare(strict_types=1);

use App\Domain\Plugins\Seams\PluginServices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Http\HostRefused;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Testing\InteractsWithTeme;
use Teme\Testing\JobRun;
use Teme\Testing\SentRequest;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class, InteractsWithTeme::class);

beforeEach(fn () => config(['teme.version' => PluginFixtures::TEME]));

afterEach(fn () => PluginFixtures::forget());

/** The outbound door a plugin granted http for api.example.com is given. */
function kitDoor(): OutboundHttp
{
    return app(PluginServices::class)->for('tests/jobs', 'jobs', ['http'], ['api.example.com'])[OutboundHttp::class];
}

it('records what a plugin’s job sends to a host it declared, and answers it', function (): void {
    $outbound = $this->outbound()->answer('api.example.com', 202, ['ok' => true]);

    $run = $this->plugin(PluginFixtures::path('jobs'))->enable()->runJob('long-call');

    expect($run->state)->toBe(JobRun::FINISHED)
        ->and($outbound->sent('api.example.com'))->toHaveCount(1)
        ->and($outbound->sent()[0]->method)->toBe('GET')
        ->and(kitDoor()->get('https://api.example.com/x')->status)->toBe(202);

    $outbound->assertSent(fn (SentRequest $sent): bool => $sent->url === 'https://api.example.com/long');
});

it('leaves the door’s own checks in place: a host the plugin didn’t declare is refused, and nothing is sent', function (): void {
    $outbound = $this->outbound();

    expect(fn () => kitDoor()->get('https://other.example.com/'))->toThrow(HostRefused::class);

    $outbound->assertNothingSent();
});

it('answers in turn, then 200', function (): void {
    $this->outbound()->answerInTurn('api.example.com', [429, 503]);

    expect(kitDoor()->get('https://api.example.com/a')->status)->toBe(429)
        ->and(kitDoor()->get('https://api.example.com/a')->status)->toBe(503)
        ->and(kitDoor()->get('https://api.example.com/a')->status)->toBe(200);
});

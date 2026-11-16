<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Testing\InteractsWithTeme;

uses(RefreshDatabase::class, InteractsWithTeme::class);

it('asks for the exact path, keeping its trailing slash', function (): void {
    $this->publish('Kept slash');

    $this->fetch('/kept-slash/')->assertOk()->assertSee('Kept slash');
    $this->fetch('/kept-slash')->assertRedirect();
});

it('sends the method, headers and body it is given', function (): void {
    $response = $this->fetch('/nowhere-at-all/', 'POST', ['Accept' => 'application/json'], '{}');

    expect($response->baseRequest->getMethod())->toBe('POST')
        ->and($response->baseRequest->headers->get('Accept'))->toBe('application/json')
        ->and($response->baseRequest->getContent())->toBe('{}');
});

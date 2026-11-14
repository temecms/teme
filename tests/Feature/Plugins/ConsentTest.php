<?php

declare(strict_types=1);

use App\Domain\Plugins\Consent;
use App\Domain\Plugins\Manifest;
use Tests\Fixtures\Plugins\PluginFixtures;

beforeEach(function (): void {
    $this->asks = fn (array $capabilities, array $hosts = [], array $hooks = ['ContentPublished']): Manifest => new Manifest([...PluginFixtures::manifest('widened'), 'capabilities' => $capabilities, 'hosts' => $hosts, 'hooks' => $hooks]);
    $this->agreed = ['capabilities' => ['content.read', 'http'], 'hosts' => ['api.example.com', '*.cdn.example.com'], 'hooks' => ['ContentPublished']];
});

it('covers what was agreed, and a host under an agreed wildcard', function (array $capabilities, array $hosts, array $hooks): void {
    expect(Consent::covers($this->agreed, ($this->asks)($capabilities, $hosts, $hooks)))->toBeTrue();
})->with([
    'the same' => [['content.read', 'http'], ['api.example.com', '*.cdn.example.com'], ['ContentPublished']],
    'less' => [['content.read'], [], ['ContentPublished']],
    'a host under the wildcard' => [['http'], ['eu.cdn.example.com'], []],
    'a new hook that needs nothing new' => [['content.read'], [], ['ContentPublished', 'ContentTrashed']],
]);

it('doesn’t cover a new capability, a new host, or a wildcard over an agreed host', function (array $capabilities, array $hosts): void {
    expect(Consent::covers($this->agreed, ($this->asks)($capabilities, $hosts)))->toBeFalse();
})->with([
    'a new capability' => [['content.read', 'http', 'media.read'], ['api.example.com']],
    'a new host' => [['http'], ['api.example.org']],
    'a wildcard over an agreed host' => [['http'], ['*.example.com']],
]);

it('lists what an update adds', function (): void {
    $added = Consent::added($this->agreed, ($this->asks)(['content.read', 'http', 'media.read'], ['api.example.com', 'eu.cdn.example.com', '*.example.com', 'api.example.org']));

    expect($added)->toBe(['capabilities' => ['media.read'], 'hosts' => ['*.example.com', 'api.example.org']])
        ->and(Consent::added($this->agreed, ($this->asks)(['content.read'])))->toBe(['capabilities' => [], 'hosts' => []]);
});

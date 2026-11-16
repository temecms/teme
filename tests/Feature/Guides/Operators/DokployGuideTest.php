<?php

declare(strict_types=1);

use Tests\Fixtures\Guides\OperatorGuide;

/*
 * The Dokploy page: installing points to the template repository, and the
 * page says what Teme itself does on Dokploy.
 */

function dokployGuide(): string
{
    return OperatorGuide::page('platforms/dokploy.md')->text();
}

it('sends installs to the template repository and its Base URL', function (): void {
    expect(dokployGuide())->toContain('https://github.com/temecms/temecms-for-dokploy')
        ->and(dokployGuide())->toContain('https://raw.githubusercontent.com/temecms/temecms-for-dokploy/main');
});

it('names the default address of the list of released compose files, and the setting that moves it', function (): void {
    expect(config('teme.updater.dokploy.compose_hashes_url'))->toBe('https://raw.githubusercontent.com/temecms/temecms-for-dokploy/main/compose-hashes.json')
        ->and(dokployGuide())->toContain('TEME_DOKPLOY_COMPOSE_HASHES_URL')
        ->and(dokployGuide())->toContain('compose-hashes.json');
});

it('says where the Dokploy API key goes', function (): void {
    expect(dokployGuide())->toContain('Settings › Updates')
        ->and(dokployGuide())->toContain('Dokploy API key');
});

it('reads as instructions for a stranger, never as notes between its makers', function (): void {
    expect(dokployGuide())->not->toMatch('/\bwe\b|decided|the owner|for now|not proved|\bI-\d+|\bpart \d+\b|\b20\d\d-\d\d-\d\d\b|what we learned/i');
});

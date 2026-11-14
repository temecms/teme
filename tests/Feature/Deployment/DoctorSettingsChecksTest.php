<?php

declare(strict_types=1);

use App\Domain\Deployment\Health\Checks\DebugMode;
use App\Domain\Deployment\Health\Checks\Mailer;
use App\Domain\Deployment\Health\Checks\PasswordHashing;
use App\Domain\Deployment\Health\Checks\RunningUser;
use App\Domain\Deployment\Health\Checks\SiteAddress;
use App\Domain\Deployment\Health\Finding;
use App\Domain\Deployment\Health\Severity;

// No database: these checks read configuration and PHP alone.

it('warns only when a production site\'s address isn\'t https', function (): void {
    config(['app.url' => 'http://example.com']);

    expect(app(SiteAddress::class)->examine())->toEqual([new Finding(Severity::Ok, 'APP_URL is http://example.com.')]);

    app()->instance('env', 'production');

    expect(app(SiteAddress::class)->examine())->toEqual([new Finding(Severity::Warning, 'APP_URL is http://example.com, not https, so passwords and session cookies cross the network unencrypted.', "Set APP_URL to the site's https address, and let the proxy in front answer https.")]);

    config(['app.url' => 'https://example.com']);

    expect(app(SiteAddress::class)->examine())->toEqual([new Finding(Severity::Ok, 'APP_URL is https://example.com.')]);
});

it('makes debug mode an error in production alone', function (): void {
    config(['app.debug' => true]);

    expect(app(DebugMode::class)->examine())->toEqual([new Finding(Severity::Ok, 'APP_DEBUG is on, which a production site must never be.')]);

    app()->instance('env', 'production');

    expect(app(DebugMode::class)->examine())->toEqual([new Finding(Severity::Error, "APP_DEBUG is on, so an error page shows visitors the site's settings and secrets.", 'Set APP_DEBUG=false.')]);

    config(['app.debug' => false]);

    expect(app(DebugMode::class)->examine())->toEqual([new Finding(Severity::Ok, 'APP_DEBUG is off.')]);
});

it('warns when a production site\'s email is never sent', function (): void {
    $fix = 'Set MAIL_MAILER and its settings for a real mail service.';
    config(['mail.default' => 'log']);

    expect(app(Mailer::class)->examine())->toEqual([new Finding(Severity::Ok, 'Email goes through log.')]);

    app()->instance('env', 'production');

    expect(app(Mailer::class)->examine())->toEqual([new Finding(Severity::Warning, 'MAIL_MAILER is log, so password resets, invitations and backup alerts are never sent.', $fix)]);

    config(['mail.default' => 'array']);

    expect(app(Mailer::class)->examine())->toEqual([new Finding(Severity::Warning, 'MAIL_MAILER is array, so password resets, invitations and backup alerts are never sent.', $fix)]);

    config(['mail.default' => 'smtp']);

    expect(app(Mailer::class)->examine())->toEqual([new Finding(Severity::Ok, 'Email goes through smtp.')]);
});

it('aims for 250 to 500 ms a hash (I-382)', function (int $milliseconds, Severity $severity): void {
    expect(PasswordHashing::verdict($milliseconds))->toBe($severity);
})->with([
    'just too quick' => [249, Severity::Warning],
    'the fastest it should be' => [250, Severity::Ok],
    'the slowest it should be' => [500, Severity::Ok],
    'just too slow' => [501, Severity::Warning],
]);

it('times one hash at the configured cost, and warns when it is too quick', function (): void {
    // phpunit.xml's Argon2id costs one MiB and one pass, well under 250 ms.
    $findings = app(PasswordHashing::class)->examine();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe(Severity::Warning)
        ->and($findings[0]->message)->toMatch('/^Hashing one password took \d+ ms, under the 250 to 500 ms Teme aims for, so a stolen hash is cheaper to crack\.$/')
        ->and($findings[0]->fix)->toBe('Raise ARGON_TIME, or ARGON_MEMORY if the server has the memory, until it takes 250 to 500 ms.');
});

it('makes running as root an error in production and a warning elsewhere, and says when it can\'t tell', function (): void {
    $as = fn (?int $id): array => (new RunningUser(fn (): ?int => $id))->examine();
    $root = "This runs as root, so files it writes under storage/ may be ones the web server can't change.";
    $fix = "Run Teme's commands as the user the web server runs as, as docker compose exec does by default.";

    expect($as(33))->toEqual([new Finding(Severity::Ok, 'Runs as user 33, not root.')])
        ->and($as(0))->toEqual([new Finding(Severity::Warning, $root, $fix)])
        ->and($as(null))->toEqual([new Finding(Severity::Info, "Can't tell which user this runs as: PHP's posix extension is missing.")]);

    app()->instance('env', 'production');

    expect($as(0))->toEqual([new Finding(Severity::Error, $root, $fix)]);
});

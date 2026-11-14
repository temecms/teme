<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->notes = fn (string $version, string ...$migrations) => Process::path(base_path())->run([
        PHP_BINARY, 'scripts/release-notes.php', 'tests/Fixtures/Updates/CHANGELOG.md', $version, ...$migrations,
    ]);
});

it('prints the version’s section without its heading, then the line the update check reads', function (): void {
    $result = ($this->notes)('0.1.0-rc.14');

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toBe(implode("\n", [
            'Teme knows its version and sees new ones.',
            '',
            '### Added',
            '',
            '- **Tools › Updates**, with `teme:updates:check`',
            '',
            '<!-- teme-release {"format":1,"security":false,"maintenance":false} -->',
            '',
        ]));
});

it('marks a section with a Security heading as a security release', function (): void {
    expect(($this->notes)('0.1.0-rc.15')->output())->toContain('<!-- teme-release {"format":1,"security":true,"maintenance":false} -->')
        ->and(($this->notes)('0.1.0-rc.15')->output())->not->toContain('0.1.0-rc.14');
});

it('marks a release that adds a migration the release before can’t run beside', function (): void {
    $flagged = 'tests/Fixtures/Deployment/migrations/flagged/2099_01_01_000100_add_deploy_flagged.php';
    $plain = 'tests/Fixtures/Deployment/migrations/probe/2099_01_01_000000_add_deploy_probe.php';

    expect(($this->notes)('0.1.0-rc.14', $plain)->output())->toContain('"maintenance":false')
        ->and(($this->notes)('0.1.0-rc.14', $plain, $flagged)->output())->toContain('"maintenance":true');
});

it('stops when the changelog has no section for the version, so a tag waits for its notes', function (): void {
    $result = ($this->notes)('0.1.0-rc.16');

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toBe('')
        ->and($result->errorOutput())->toContain('tests/Fixtures/Updates/CHANGELOG.md has no section for 0.1.0-rc.16. Write it before tagging.');
});

it('reads the project’s own changelog', function (): void {
    expect(($this->notes)('0.1.0-rc.13')->exitCode())->toBe(0);
})->skip(fn (): bool => ! str_contains((string) file_get_contents(base_path('CHANGELOG.md')), '## [0.1.0-rc.13]'));

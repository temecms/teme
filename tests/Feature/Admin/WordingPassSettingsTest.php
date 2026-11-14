<?php

declare(strict_types=1);

beforeEach(function (): void {
    // A file's source with each run of whitespace made one space, so a
    // sentence Prettier wraps reads as one line.
    $this->flat = fn (string $path): string => (string) preg_replace('/\s+/', ' ', uiComponent($path));

    // Where a piece of source starts; the test fails when it isn't there.
    $this->at = function (string $source, string $needle, int $from = 0): int {
        $at = strpos($source, $needle, $from);
        expect($at)->not->toBeFalse("Not in the source: {$needle}");

        return (int) $at;
    };
});

it('gives the pin limit in the Personalise menu dialog’s description, not under the list', function (): void {
    $source = ($this->flat)('components/personalise-menu-dialog.tsx');

    // Anchored on the words after {MAX_PINNED}: Prettier may put a {' '} before it.
    expect(($this->at)($source, 'entries to the top. Hidden entries stay in search'))
        ->toBeGreaterThan(($this->at)($source, '<DialogDescription>'))
        ->toBeLessThan(($this->at)($source, '</DialogDescription>'))
        ->and($source)->not->toContain('entries to the top of your menu');
});

it('offers Open Search and social as a button beside Home’s search engines notice', function (): void {
    $source = ($this->flat)('pages/dashboard.tsx');
    $detail = ($this->at)($source, '{searchEngines.detail}');

    expect(($this->at)($source, 'asChild', $detail))->toBeLessThan(($this->at)($source, '{searchEngines.actionLabel}', $detail))
        ->and($source)->not->toContain('className="font-medium underline underline-offset-2"');
});

it('explains Search and social’s rows above them, not under them', function (): void {
    $source = ($this->flat)('pages/settings/search-and-social.tsx');

    expect(($this->at)($source, '{section.note}'))
        ->toBeGreaterThan(($this->at)($source, "section.key === 'titles-and-listing' && ("))
        ->toBeLessThan(($this->at)($source, 'aria-label="Titles, and hiding from search engines, by kind of page"'))
        ->and($source)->toContain("section.key !== 'titles-and-listing'");
});

it('offers Choose the home page as Addresses’ header button', function (): void {
    $source = ($this->flat)('pages/settings/addresses.tsx');

    expect(($this->at)($source, 'Choose the home page'))
        ->toBeGreaterThan(($this->at)($source, 'title="Addresses"'))
        ->toBeLessThan(($this->at)($source, 'Old addresses keep working'))
        ->and($source)->not->toContain('Settings › Reading');
});

it('says where one item’s code goes in Site code’s description, not at its foot', function (): void {
    $source = ($this->flat)('pages/settings/site-code.tsx');

    expect(($this->at)($source, 'add an Embed card to that item instead."'))->toBeLessThan(($this->at)($source, 'data-test="site-code-add"'))
        ->and($source)->not->toContain('There’s no code for a single post or page');
});

it('says where the bio shows in a hint under the box, not in its label', function (): void {
    $source = ($this->flat)('pages/account/profile.tsx');

    expect(($this->at)($source, 'Shown on your author page and under your posts.'))
        ->toBeGreaterThan(($this->at)($source, 'id="bio"'))
        ->toBeLessThan(($this->at)($source, 'id="bio-error"'))
        ->and($source)->toContain('aria-describedby="bio-hint bio-error"')
        ->not->toContain('Bio — shown');
});

it('offers Resend confirmation email as a button, not a link inside a sentence', function (): void {
    $source = ($this->flat)('pages/account/profile.tsx');
    $line = ($this->at)($source, 'Your email address isn’t confirmed yet.');

    expect(($this->at)($source, 'asChild', $line))->toBeLessThan(($this->at)($source, 'Resend confirmation email', $line))
        ->and($source)->not->toContain('Click here');
});

it('offers Open inbox as a button beside the Inbox heading', function (): void {
    $source = ($this->flat)('pages/account/notifications.tsx');
    $heading = ($this->at)($source, "title={__('Inbox')}");

    expect(($this->at)($source, 'Open inbox', $heading))->toBeLessThan(($this->at)($source, '{notices.length === 0'))
        ->and(($this->at)($source, 'asChild', $heading))->toBeLessThan(($this->at)($source, 'Open inbox', $heading))
        ->and($source)->not->toContain('Open your inbox');
});

it('warns what regenerating does beside the Regenerate codes button', function (): void {
    $source = ($this->flat)('components/two-factor-recovery-codes.tsx');

    expect(($this->at)($source, 'New codes replace these. The old ones stop working.'))
        ->toBeGreaterThan(($this->at)($source, 'Regenerate codes'))
        ->toBeLessThan(($this->at)($source, 'id="recovery-codes-section"'))
        ->and($source)->not->toContain('Each recovery code can be used once');
});

it('says why the reader is on the sign-in screen above the form', function (): void {
    $source = ($this->flat)('pages/auth/login.tsx');

    expect(($this->at)($source, '{status && ('))->toBeLessThan(($this->at)($source, '<Form'));
});

it('offers Back to sign in on its own, not inside a sentence', function (): void {
    expect(($this->flat)('pages/auth/forgot-password.tsx'))
        ->toContain('<TextLink href={login()}>Back to sign in</TextLink>')
        ->not->toContain('Or, return to');
});

it('offers the other way to sign in as a button of its own under Continue', function (): void {
    $source = ($this->flat)('pages/auth/two-factor-challenge.tsx');
    $continue = ($this->at)($source, 'Continue');

    expect(($this->at)($source, 'variant="outline"', $continue))->toBeLessThan(($this->at)($source, '{authConfigContent.toggleText}', $continue))
        ->and($source)->not->toContain('or you can');
});

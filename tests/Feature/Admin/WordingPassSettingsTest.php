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

it('says in the Customise menu’s description that only the person sees the changes, with no pin limit', function (): void {
    $source = ($this->flat)('components/personalise-menu-dialog.tsx');

    expect($source)->toContain('Only you see these changes. The command palette still finds hidden entries.')
        ->and($source)->not->toContain('entries to the top');
});

it('offers Open Search and social as a button beside Home’s search engines notice', function (): void {
    $source = ($this->flat)('pages/dashboard.tsx');
    $detail = ($this->at)($source, '{searchEngines.detail}');

    expect(($this->at)($source, 'asChild', $detail))->toBeLessThan(($this->at)($source, '{searchEngines.actionLabel}', $detail))
        ->and($source)->not->toContain('className="font-medium underline underline-offset-2"');
});

it('explains each of Search and social’s sections in its heading, not under its settings', function (): void {
    $source = ($this->flat)('pages/settings/search-and-social.tsx');

    expect($source)->toContain('description={describe(key)}')
        ->and($source)->not->toContain('section.note');
});

it('links Reading inside Addresses’ description, with no header button or info notice', function (): void {
    $source = ($this->flat)('pages/settings/addresses.tsx');

    expect($source)->toContain("{__('The home page is chosen in')}")
        ->and($source)->not->toContain('Choose the home page')
        ->and($source)->not->toContain('Old addresses keep working')
        ->and($source)->not->toContain('Settings › Reading');
});

it('says where one item’s code goes in Site code’s description, not at its foot', function (): void {
    $source = ($this->flat)('pages/settings/site-code.tsx');

    expect(($this->at)($source, 'add an Embed card to that item instead.'))->toBeGreaterThan(($this->at)($source, '<SettingsPage'))
        ->toBeLessThan(($this->at)($source, '<Head title='))
        ->and($source)->not->toContain('There’s no code for a single post or page');
});

it('says where the bio shows in a hint under the box, not in its label', function (): void {
    $source = ($this->flat)('pages/account/profile.tsx');
    $field = ($this->flat)('components/ui/field.tsx');
    $bio = ($this->at)($source, '<Field htmlFor="bio"');

    // The words are the bio Field's help, never its label; Field draws the help after the box and before the error, both tied to the box.
    expect(($this->at)($source, "help={__( 'Shown on your author page and under your articles. Up to 1,000 characters.', )}", $bio))
        ->toBeGreaterThan(($this->at)($source, "label={__('Short biography')}", $bio))
        ->toBeLessThan(($this->at)($source, 'id="bio"', $bio))
        ->and(($this->at)($field, '<FieldHelp id={id} help={help} />'))
        ->toBeGreaterThan(($this->at)($field, '{tie(children, id, help, error)}'))
        ->toBeLessThan(($this->at)($field, '<FieldError id={id} error={error}'))
        ->and($field)->toContain('const ids = [help ? `${id}-help` : null, error ? `${id}-error` : null]')
        ->and($source)->not->toContain('Bio — shown');
});

it('offers Send it again as the notice\'s button, not a link inside a sentence', function (): void {
    $source = ($this->flat)('pages/account/profile.tsx');
    $notice = ($this->at)($source, 'data-test="email-unverified"');

    expect(($this->at)($source, 'asChild', $notice))->toBeLessThan(($this->at)($source, "{__('Send it again')}", $notice))
        ->and(($this->at)($source, "{__('Send it again')}", $notice))->toBeLessThan(($this->at)($source, 'Your email address isn’t verified yet.', $notice))
        ->and($source)->not->toContain('Click here');
});

it('offers Open inbox as a button beside the Inbox heading', function (): void {
    $source = ($this->flat)('pages/account/notifications.tsx');
    $heading = ($this->at)($source, "{__('Inbox')}");

    expect(($this->at)($source, 'Open inbox', $heading))->toBeLessThan(($this->at)($source, '{(notices.length > 0'))
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

    expect(($this->at)($source, 'notices='))->toBeLessThan(($this->at)($source, '<Form'));
});

it('offers Back to sign in on its own, not inside a sentence', function (): void {
    expect(($this->flat)('pages/auth/forgot-password.tsx'))
        ->toContain("<AuthLink asChild> <Link href={login()}>{__('Back to sign in')}</Link> </AuthLink>")
        ->not->toContain('Or, return to');
});

it('offers the other way to sign in as a link of its own under Continue', function (): void {
    $source = ($this->flat)('pages/auth/two-factor-challenge.tsx');
    $continue = ($this->at)($source, "{__('Continue')}");

    expect(($this->at)($source, '<AuthLink', $continue))->toBeLessThan(($this->at)($source, '{authConfigContent.toggleText}', $continue))
        ->and($source)->not->toContain('or you can');
});

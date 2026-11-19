<?php

declare(strict_types=1);

// Pest.php binds every Feature test to TestCase. Nothing here touches the
// database, so this file does not use RefreshDatabase.

beforeEach(function (): void {
    // A file's source with each run of whitespace made one space, so a call Prettier wraps reads as one line.
    $this->flat = fn (string $path): string => (string) preg_replace('/\s+/', ' ', uiComponent($path));

    // Every word a file sends through __() or __n(), as written.
    $this->words = function (string $path): array {
        preg_match_all("/__n?\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", ($this->flat)($path), $found);

        return $found[1];
    };
});

it('draws the views as in-page links, invites from a dialog opened in the header, and has no side panel', function (): void {
    $page = uiComponent('pages/users/index.tsx');
    $invite = uiComponent('components/people/invite-dialog.tsx');

    expect($page)
        ->toContain('<InPageNav')
        ->toContain("label={__('Views')}")
        ->toContain('data-test="people-invite"')
        ->toContain('<InviteDialog')
        ->not->toContain("from '@/components/ui/sheet'")
        ->and(($this->words)('pages/users/index.tsx'))->toContain('Everyone who can sign in to this site. People you invite choose their own password.')
        ->and($invite)->toContain('data-test="invite-dialog"')
        ->toContain("from '@/components/ui/dialog'")
        ->toContain('UserController.store.form()')
        ->toContain('roles[roles.length - 1]?.value')
        ->and(($this->words)('components/people/invite-dialog.tsx'))
        ->toContain('They get an email with a link that works for :count day, where they choose their own password. You can send it again or revoke it under Invited.|They get an email with a link that works for :count days, where they choose their own password. You can send it again or revoke it under Invited.')
        ->toContain('Sending may ask for your password first.');
});

it('lists invitations in a table of their own, sent again or given a role on the row, revoked from their menu, and asks before revoking', function (): void {
    $source = ($this->flat)('components/people/row-actions.tsx');
    $page = uiComponent('pages/users/index.tsx');
    preg_match('/function invitationActions\(.*?\} \/\*\*/s', $source, $line);
    preg_match('/function invitationMoreActions\(.*?\} \/\*\*/s', $source, $more);

    expect($line[0] ?? '')
        ->toMatch("/id: 'resend',.*label: __\\('Send again'\\).*id: 'role',.*label: __\\('Change role…'\\)/s")
        ->not->toContain('suspend')
        ->and($more[0] ?? '')
        ->toMatch("/id: 'revoke',.*label: __\\('Revoke invitation'\\)/s")
        ->toMatch("/<ConfirmAction\\b[^<]*?confirmLabel=\\{__\\('Revoke invitation'\\)\\}/s")
        ->toContain("title={__('Revoke the invitation to :email?', { email: user.email, })}")
        ->toContain('UserController.revokeInvitation.form(')
        ->toContain("'The link in their email stops working, and the account waiting for them is removed. You can invite them again.'")
        ->and($page)->toContain("__('Pending invitations')")
        ->toContain("header: __('Invited')")
        ->toContain("header: __('Link')")
        ->toContain("__('by :name', { name: user.invitedBy })")
        ->toContain("__('Works until :date', {")
        ->toContain("<StatusPill tone=\"danger\">{__('Expired')}</StatusPill>");
});

it('changes a role in a dialog, its select labelled, its hint for a person or an invitation, saying the password may be asked', function (): void {
    $source = ($this->flat)('components/people/role-dialog.tsx');

    expect($source)
        ->toContain('data-test="role-dialog"')
        ->toContain("__('Change the role of :name', { name: person.name, })")
        ->toMatch("/<Field htmlFor=\"change-role\" label=\\{__\\('Role'\\)\\}/")
        ->toContain('id="change-role"')
        ->toContain('<span id="change-role-hint">')
        ->toMatch("/person\\.invitedAt === null \\? __\\( 'They’re signed out everywhere, so their next sign-in uses the new role\\.', \\) : __\\( 'They’ll have this role once they accept the invitation\\.', \\)/")
        ->toContain('UserController.updateRole.form(')
        ->and(($this->words)('components/people/role-dialog.tsx'))->toContain('Changing a role may ask for your password first.');
});

it('locks your own row\'s Change role…, saying why, and draws no menu for it', function (): void {
    $source = ($this->flat)('components/people/person-cell.tsx');
    preg_match('/function OwnRowLock\(.*?\} \/\*\*/s', $source, $lock);

    expect($lock[0] ?? '')
        ->toContain('aria-disabled="true"')
        ->toContain('aria-describedby="own-role-reason"')
        ->toContain('id="own-role-reason"')
        ->toContain('data-test="own-row-lock"')
        ->toContain('<Lock aria-hidden="true" className="size-4" />')
        ->toContain("__( 'You can’t change your own role. Ask another administrator.', )")
        ->toContain('editProfile()')
        ->toContain("__('Manage yourself in Account')")
        ->and($source)->toMatch('/isYou \? \( <OwnRowLock \/>/')
        ->toMatch("/isYou && \\( <StatusPill tone=\"neutral\" flat> \\{__\\('You'\\)\\}/")
        // Your own row has no More menu either.
        ->and(($this->flat)('pages/users/index.tsx'))->toMatch('/isYou\(user\) \? \[\]/');
});

it('deletes in a dialog too, offering every account but the person, whatever the view', function (): void {
    $source = ($this->flat)('components/people/delete-dialog.tsx');

    expect($source)
        ->toContain('data-test="delete-dialog"')
        ->toMatch('/reassignOptions\.filter\( \(option\) => option\.value !== person\.id,? \)/')
        ->toContain("emptyLabel={__( 'No one — they wrote nothing', )}")
        ->toContain('UserController.destroy.form(')
        ->not->toContain('<Sheet')
        ->and(($this->words)('components/people/delete-dialog.tsx'))->toContain('Deleting may ask for your password first.');
});

it('says what each empty view holds, and offers an invitation where one would fill it', function (): void {
    $source = ($this->flat)('pages/users/index.tsx');
    preg_match("/if \\(view === 'suspended'\\) \\{.*?\\} return/s", $source, $suspended);

    expect($source)
        ->toContain("title={__('No pending invitations')}")
        ->toContain("title={__('No :role yet', { role: tabLabel.toLowerCase() })}")
        ->and(($this->words)('pages/users/index.tsx'))
        ->toContain('People you invite get an email with a link that works for :days.')
        ->toContain('Invite someone and give them this role.')
        // Suspended has its own text, and no invitation would fill it.
        ->and($suspended[0] ?? '')->toContain("title={__('Nobody is suspended')}")
        ->toContain('Someone you suspend can’t sign in, and everything they wrote stays as it is.')
        ->not->toContain('action=');
});

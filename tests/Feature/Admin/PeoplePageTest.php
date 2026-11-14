<?php

declare(strict_types=1);

// Pest.php binds every Feature test to TestCase. Nothing here touches the
// database, so this file does not use RefreshDatabase.

it('draws the views as tabs, invites from a dialog opened in the header, and has no side panel', function (): void {
    expect(uiComponent('pages/users/index.tsx'))
        ->toContain('<TabStrip')
        ->toContain('label="Views"')
        ->toContain('data-test="people-invite"')
        ->toContain('data-test="invite-dialog"')
        ->toContain("from '@/components/ui/dialog'")
        ->not->toContain("from '@/components/ui/sheet'")
        ->toContain("'Everyone who can sign in to this site. People you invite choose their own password.'")
        ->toContain('works for ${daysWords(days)}, where they choose their own password. You can send it again or revoke it under Invited.')
        ->toContain("'Sending may ask for your password first.'")
        ->toContain('UserController.store.form()')
        ->toContain('roles[roles.length - 1]?.value');
});

it('lists invitations in a table of their own, sent again, given a role or revoked from their menu, and asks before revoking', function (): void {
    $source = uiComponent('pages/users/index.tsx');
    preg_match('/function invitationActions\(.*?\n}\n/s', $source, $actions);

    expect($actions[0] ?? '')
        ->toMatch("/id: 'resend',.*label: 'Send again'.*id: 'role',.*label: 'Change role…'.*id: 'revoke',.*label: 'Revoke invitation'/s")
        ->not->toContain('suspend')
        ->toMatch('/<ConfirmAction\b[^<]*?confirmLabel="Revoke invitation"/s')
        ->toContain('title={`Revoke the invitation to ${user.email}?`}')
        ->toContain('UserController.revokeInvitation.form(')
        ->and($source)->toContain('caption="Pending invitations"')
        ->toContain("header: 'Invited'")
        ->toContain("header: 'Link'")
        ->toContain('`by ${user.invitedBy}`')
        ->toContain('`Works until ${shortDate(user.linkWorksUntil)}`')
        ->toContain('<Badge variant="destructive">Expired</Badge>')
        ->toContain("'The link in their email stops working, and the account waiting for them is removed. You can invite them again.'");
});

it('changes a role in a dialog, its select labelled, its hint for a person or an invitation, saying the password may be asked', function (): void {
    expect(uiComponent('pages/users/index.tsx'))
        ->toContain('data-test="role-dialog"')
        ->toContain('`Change the role of ${person.name}`')
        ->toMatch('/<Label htmlFor="change-role">\s*Role\s*<\/Label>/')
        ->toContain('id="change-role"')
        ->toContain("'They’re signed out everywhere, so their next sign-in uses the new role.'")
        ->toContain("'They’ll have this role once they accept the invitation.'")
        ->toMatch('/person\.invitedAt === null\s*\?\s*ROLE_HINT_PERSON\s*:\s*ROLE_HINT_INVITATION/')
        ->toContain("'Changing a role may ask for your password first.'")
        ->toContain('UserController.updateRole.form(');
});

it('locks your own row\'s Change role…, saying why, and draws no menu for it', function (): void {
    $source = uiComponent('pages/users/index.tsx');
    preg_match('/function OwnRowLock\(.*?\n}\n/s', $source, $lock);

    expect($lock[0] ?? '')
        ->toContain('aria-disabled="true"')
        ->toContain('aria-describedby="own-role-reason"')
        ->toContain('id="own-role-reason"')
        ->toContain('data-test="own-row-lock"')
        ->toContain('<Lock aria-hidden="true" />')
        ->toContain('{OWN_ROLE_REASON}')
        ->toContain('editProfile()')
        ->toContain('Manage yourself in Account')
        ->and($source)->toContain("'You cannot change your own role. Ask another administrator.'")
        ->toMatch('/user\.id === currentUserId\s*\?\s*\(?\s*<OwnRowLock \/>/')
        ->toMatch('/user\.id === currentUserId\s*&&\s*\(?\s*<Badge variant="outline">You<\/Badge>/');
});

it('deletes in a dialog too, offering every account but the person, whatever the view', function (): void {
    expect(uiComponent('pages/users/index.tsx'))
        ->toContain('data-test="delete-dialog"')
        ->toMatch('/reassignOptions\.filter\(\s*\(option\) =>\s*option\.value !== person\.id,?\s*\)/')
        ->toContain('emptyLabel="No one — they wrote nothing"')
        ->toContain("'Deleting may ask for your password first.'")
        ->toContain('UserController.destroy.form(')
        ->not->toContain('<Sheet');
});

it('says what each empty view holds, and offers an invitation where one would fill it', function (): void {
    expect(uiComponent('pages/users/index.tsx'))
        ->toContain('title="No pending invitations"')
        ->toContain("'Nobody is suspended'")
        ->toContain('`No ${tabLabel.toLowerCase()} yet`')
        ->toMatch("/action=\{\s*view === 'suspended'\s*\?\s*undefined\s*:\s*inviteButton\s*\}/");
});

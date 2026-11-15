<?php

declare(strict_types=1);

// Pest.php binds every Feature test to TestCase. Nothing here touches the
// database, so this file does not use RefreshDatabase.

it('ties every field error to its field', function (string $file, ?string $partner = null): void {
    // A source scan: every InputError carries an id, and the controls name
    // those ids with aria-describedby. A partner is the file that draws the
    // control when another file draws the error. An error given to Field is
    // tied by Field itself, which writes its id and the control's
    // aria-describedby (resources/js/components/ui/field.tsx).
    $source = uiComponent($file);
    $controls = $source.($partner === null ? '' : uiComponent($partner));

    preg_match_all('/<InputError\b(?:=>|[^>])*>/s', $source, $errors);
    preg_match_all('/<Field(?:Inline)?\b(?:=>|[^>])*\berror=/s', $source, $fieldErrors);

    expect([...$errors[0], ...$fieldErrors[0]])->not->toBeEmpty();

    foreach ($errors[0] as $error) {
        expect($error)->toContain('id=');
    }

    expect(substr_count($controls, 'aria-describedby='))->toBeGreaterThanOrEqual(count($errors[0]));
})->with([
    ['pages/content/index.tsx'],
    ['pages/terms/index.tsx'],
    ['pages/auth/login.tsx'],
    ['pages/auth/forgot-password.tsx'],
    ['pages/auth/reset-password.tsx'],
    ['pages/auth/confirm-password.tsx'],
    ['pages/auth/accept-invitation.tsx'],
    ['pages/auth/two-factor-challenge.tsx'],
    ['components/two-factor-setup-modal.tsx'],
    ['components/media/attachment-fields.tsx'],
    ['components/comments/quick-edit.tsx'],
    ['components/comments/inline-reply.tsx'],
    ['components/fields/settings-field.tsx', 'components/fields/field-control.tsx'],
    ['components/seo/who-publishes.tsx'],
    ['pages/settings/site-code.tsx'],
    ['components/tools/redirect-form.tsx'],
    ['components/tools/import-redirects.tsx'],
    ['components/import/connect-form.tsx'],
    ['components/import/choices-form.tsx'],
    ['components/backups/schedule-form.tsx'],
    ['components/backups/place-fields.tsx'],
    ['components/backups/typed-back.tsx'],
    ['pages/tools/backups/set-up.tsx'],
    ['pages/tools/backups/sftp.tsx'],
    ['pages/tools/backups/place.tsx'],
    ['pages/tools/backups/new-key.tsx'],
    ['components/backups/key-actions.tsx'],
]);

it('labels every select and every input', function (string $file): void {
    // A source scan: a control is labelled when it carries aria-label or
    // aria-labelledby, or an id that a <Label htmlFor> in the same file names.
    $source = uiComponent($file);

    preg_match_all('/<(?:Input|PasswordInput|InputOTP|Checkbox|SelectField|select|textarea)\b((?:=>|[^>])*)>/s', $source, $controls);

    expect($controls[0])->not->toBeEmpty();

    foreach ($controls[1] as $attributes) {
        $named = str_contains($attributes, 'aria-label=') || str_contains($attributes, 'aria-labelledby=');
        $labelFor = preg_match('/\bid=(\{`[^`]*`\}|\{[^}]*\}|"[^"]*")/', $attributes, $id) === 1
            && str_contains($source, 'htmlFor='.$id[1]);

        expect($named || $labelFor)->toBeTrue("An unlabelled control in {$file}:\n{$attributes}");
    }
})->with([
    // The invite form's role select and the row role select, which the audit
    // found unlabelled, are on the Users page.
    'components/people/invite-dialog.tsx',
    'components/people/role-dialog.tsx',
    'components/people/delete-dialog.tsx',
    'pages/content/index.tsx',
    'pages/terms/index.tsx',
    'pages/media/index.tsx',
    'pages/account/profile.tsx',
    'pages/account/security.tsx',
    'components/fields/pairs-setting.tsx',
    'pages/settings/search-and-social.tsx',
    'components/fields/hours-setting.tsx',
    'components/seo/who-publishes.tsx',
    'pages/auth/login.tsx',
    'pages/auth/forgot-password.tsx',
    'pages/auth/reset-password.tsx',
    'pages/auth/confirm-password.tsx',
    'pages/auth/accept-invitation.tsx',
    'pages/auth/two-factor-challenge.tsx',
    'components/two-factor-setup-modal.tsx',
    'components/media/attachment-fields.tsx',
    'components/media/media-filters.tsx',
    'components/comments/quick-edit.tsx',
    'components/comments/inline-reply.tsx',
    'pages/settings/site-code.tsx',
    'components/tools/redirect-form.tsx',
    'components/tools/import-redirects.tsx',
    'components/import/connect-form.tsx',
    'components/import/choices-form.tsx',
    'components/backups/schedule-form.tsx',
    'components/backups/place-fields.tsx',
    'components/backups/typed-back.tsx',
    'pages/tools/backups/set-up.tsx',
    'pages/tools/backups/sftp.tsx',
    'pages/tools/backups/place.tsx',
    'pages/tools/backups/new-key.tsx',
    'components/backups/key-actions.tsx',
]);

it('asks before anything that cannot be undone', function (string $file, string $needle): void {
    // A source scan: the control is wrapped in a ConfirmAction that carries
    // this attribute.
    expect(uiComponent($file))->toMatch('/<ConfirmAction\b[^<]*?'.preg_quote($needle, '/').'/s')
        ->and(uiComponent('components/confirm-action.tsx'))->toContain("from '@/components/ui/dialog'");
})->with([
    'deleting permanently, from the row' => ['pages/content/index.tsx', "confirmLabel={__('Delete permanently')}"],
    'deleting a term' => ['pages/terms/index.tsx', "confirmLabel={__('Delete :singular', { singular })}"],
    'disabling two-factor sign-in' => ['components/manage-two-factor.tsx', "'Turn off two-factor sign-in',"],
    'deleting redirects' => ['pages/tools/redirects.tsx', 'confirmLabel={deleteLabel('],
    'forgetting a site’s application password' => ['components/import/run-report.tsx', "confirmLabel={__('Forget this site')}"],
    'moving a file to the trash, from its drawer' => ['components/media-drawer.tsx', "confirmLabel={__('Move to trash')}"],
    'deleting a file permanently, from its drawer' => ['components/media-drawer.tsx', "confirmLabel={__('Delete permanently')}"],
    'removing a place' => ['components/backups/places-list.tsx', "confirmLabel={__('Remove :name', {"],
    'replacing a recovery key' => ['components/backups/key-actions.tsx', "confirmLabel={__('Replace :label', { label })}"],
    'removing a place to start again' => ['components/backups/verdict.tsx', "confirmLabel={__('Remove :name', {"],
]);

it('asks before deleting permanently in bulk, in a dialog that names each item', function (): void {
    expect(uiComponent('pages/content/index.tsx'))->toMatch("/<ConfirmItems\\b[^<]*?confirmLabel=\\{__\\('Delete permanently'\\)\\}/s")
        ->and(uiComponent('components/list/confirm-items.tsx'))->toContain("from '@/components/ui/dialog'");
});

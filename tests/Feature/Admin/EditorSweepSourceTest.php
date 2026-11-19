<?php

declare(strict_types=1);

it('says where saving stands in words that tell a writer when work isn\'t safe (I-1316)', function (): void {
    expect(uiComponent('components/editor/save-state.tsx'))
        ->toContain("phrase: 'Not saved: offline'")
        ->toContain("phrase: 'Not saved: changed by someone else'")
        ->toContain("phrase: 'Not saved'")
        ->toContain("phrase: 'Saving…'")
        ->toContain('Everything here is saved.')
        ->toContain('Keep this tab open until then.')
        ->toContain('data-test="save-state"')
        ->toContain('data-state={words.kind}')
        ->toContain('data-saved-at={savedAt ?? undefined}')
        ->not->toContain('No changes yet')
        ->not->toContain('Unsaved changes')
        ->not->toContain('Not connected');
});

it('shows the item\'s state as a status pill after the crumbs in the editor\'s bar (I-1316)', function (): void {
    expect(uiComponent('components/editor/status-chip.tsx'))
        ->toContain("'Published, with unpublished changes'")
        ->toContain("'Published, with an update in review'")
        ->toContain("'Published, with an update scheduled'")
        ->toContain('data-test="status-chip"')
        ->toContain("'Private'")
        ->and(uiComponent('components/editor/editor-header.tsx'))
        ->toContain('statusChip')
        ->not->toContain('title?: string')
        ->and(uiComponent('pages/content/edit.tsx'))
        ->toContain('statusChip={')
        ->toContain('setUnpublished(answer.body.unpublishedChanges)')
        ->and(uiComponent('components/editor/status-chip.tsx'))->toContain('<StatusPill')
        ->and(uiComponent('components/editor/rail/publish-panel.tsx'))
        ->not->toContain('<StatusChip')
        ->not->toContain('function statusLabel')
        ->and(uiComponent('components/editor/editor-rail.tsx'))
        ->not->toContain('function statusLabel');
});

it('opens Share as a pane of the rail, which saves before it makes a link (I-1316)', function (): void {
    expect(uiComponent('components/editor/editor-header.tsx'))
        ->toMatch("/id: 'share',[^}]*icon: Share2,/")
        ->toContain('canShare')
        ->not->toContain('<SharePanel')
        ->and(uiComponent('components/editor/share-panel.tsx'))
        ->toContain('export function SharePane')
        ->toContain('await saveFirst()')
        ->toContain('Making one saves your changes first.')
        ->toContain("link.createdByName ?? 'a removed account'")
        ->toContain('aria-label="Preview links"')
        ->not->toContain('DropdownMenu')
        ->and(uiComponent('pages/content/edit.tsx'))
        ->toContain("id === 'share'")
        ->toContain('saveFirst={checkpoint}')
        ->toContain('canShare={session.can.edit && themeRegion === null}');
});

it('schedules from one Publish row with one date-and-time field on the site\'s clock, and the bar\'s one button follows it (I-1316)', function (): void {
    expect(uiComponent('components/editor/rail/publish-when.tsx'))
        ->toContain("label: __('Immediately')")
        ->toContain("label: __('At a set time…')")
        ->toContain("'When you approve it'")
        ->toContain("'After review'")
        ->toContain('Reschedule…')
        ->toContain('const decides = canPublish && !pending;')
        ->toContain('data-test="publish-when"')
        ->and(uiComponent('components/editor/schedule-control.tsx'))
        ->toContain('type="datetime-local"')
        ->toContain("past: 'Choose a time in the future.'")
        ->toContain('aria-label="Quick choices"')
        ->not->toContain('type="date"')
        ->not->toContain('type="time"')
        ->and(uiComponent('components/editor/editor-rail.tsx'))
        ->toContain("'Schedule update'")
        ->toContain('futureIso(when.at, siteTimezone)')
        ->toContain('Save draft')
        ->toContain('More publishing choices')
        ->not->toContain('onScheduling')
        ->not->toContain('Later (I-190): the AI panel');
});

it('previews the latest saved version in a tab of its own, opened inside the click and pointed there once saved (I-1316)', function (): void {
    expect(uiComponent('pages/content/edit.tsx'))
        ->toContain('`teme-preview-${session.id}`')
        ->toContain('tab.opener = null;')
        ->toContain('contentPreview(session.id).url')
        ->toContain('tab.close();')
        ->and(uiComponent('components/editor/editor-rail.tsx'))
        ->toContain('data-test="editor-preview"')
        ->toContain(', opens in a new tab');
});

it('sets the item\'s visibility with a select whose meaning is its title, offers the author as a select to those who may change it, and links History in words (I-1700)', function (): void {
    expect(uiComponent('components/editor/rail/publish-panel.tsx'))
        ->toContain('id="editor-visibility"')
        ->toContain("public: 'Anyone can read it once it’s published.'")
        ->toContain('id="editor-author"')
        ->not->toContain('Changing it changes the published ${noun} at once.')
        ->toContain("'1 revision'")
        ->toContain("<Row label={__('History')}>")
        ->not->toContain('<select')
        ->and(uiComponent('pages/content/edit.tsx'))
        ->toContain('ContentEditorController.author(session.id).url')
        ->toContain('Reload it before changing the author.')->not->toContain('changed this article');
});

it('keeps the featured image compact, with its credit, a Missing chip, and a hint that says why (I-237)', function (): void {
    expect(uiComponent('components/editor/fields/media-control.tsx'))
        ->toContain('Alt text for this {noun}')
        ->toContain('data-test="alt-missing"')
        ->toContain('data-test="featured-credit"')
        ->toContain("'The library has no alt text for this image, so write one here.'")
        ->toContain('Replace…')
        ->toContain('item.licence')
        ->toContain('sm:grid-cols-[190px_minmax(0,1fr)]')
        ->not->toContain('placeholder=')
        ->not->toContain('Alt text for this use');
});

it('sends every failed autosave through saveFailure(), and shows its phrase in the header (issue 6)', function (): void {
    expect(uiComponent('components/editor/use-autosave.ts'))
        ->toContain('const failed = saveFailure(status, reason);')
        ->toContain('reasonOf(answer.body),')
        ->toContain('answer.retryAfter ?? null,')
        ->toContain("fail(null, '');")
        ->not->toContain('Autosave answered')
        ->not->toContain('setRefusal')
        ->and(uiComponent('components/editor/save-state.tsx'))
        ->toContain('phrase: failure.phrase')
        ->toContain('detail: failure.description')
        ->and(uiComponent('pages/content/edit.tsx'))
        ->toContain('failure={saving.failure}');
});

it('shows a failed autosave as one error toast that stays until a save succeeds (issue 6)', function (): void {
    expect(uiComponent('pages/content/edit.tsx'))
        ->toContain("const SAVE_NOTICE = 'editor-save-failure';")
        ->toContain('id: SAVE_NOTICE,')
        ->toContain('duration: Infinity,')
        ->toContain('closeButton: true,')
        ->toContain('toast.dismiss(SAVE_NOTICE);');
});

it('holds a visit away for an ordinary save after any on its way, and keeps keepalive for a true unload (issue 6)', function (): void {
    expect(uiComponent('components/editor/use-autosave.ts'))
        ->toContain('holdThenGo(() => replay(visit));')
        ->toContain('void flushRef.current().then(() => {')
        ->toContain("window.addEventListener('pagehide', leaving);")
        ->not->toContain('            leaving();');
});

it('never lets a refused keepalive send throw, and sets returnValue for older Safari (issue 6)', function (): void {
    expect(uiComponent('components/editor/use-autosave.ts'))
        ->toContain('}).catch(() => undefined);')
        ->toContain("event.returnValue = '';");
});

<?php

declare(strict_types=1);

it('puts the jobs button and the bell in the header, before the account menu, where the signpost was', function (): void {
    $header = uiComponent('components/app-sidebar-header.tsx');

    expect($header)->toContain('<JobsButton')
        ->toContain('<InboxBell')
        ->toContain('role="status"')
        ->toContain('Later (I-1391)')
        ->not->toContain('Later (I-221)')
        ->and(strpos($header, '<JobsButton'))->toBeLessThan(strpos($header, '<InboxBell'))
        ->and(strpos($header, '<InboxBell'))->toBeLessThan(strpos($header, '<AccountMenu />'));
});

it('draws both panels as non-modal dialogs inside the header, closed by a visit elsewhere', function (): void {
    expect(uiComponent('components/header/header-panel.tsx'))
        ->toContain('modal={false}')
        ->toContain("router.on('navigate'")
        ->toContain('aria-describedby={undefined}')
        ->not->toContain('Portal');
});

it('polls only while a job runs and the tab is visible, and says when jobs end', function (): void {
    expect(uiComponent('components/header/use-header-activity.ts'))
        ->toContain('export const POLL_MS = 3000;')
        ->toContain("document.visibilityState === 'visible'")
        ->toContain("new Event('teme:jobs-ended')")
        ->and(uiComponent('components/header/jobs-button.tsx'))
        ->toContain('motion-safe:animate-spin');
});

it('leaves no signpost for the header in the inbox’s and the jobs’ own files', function (): void {
    expect((string) file_get_contents(app_path('Domain/Inbox/Queries/InboxFor.php')))->not->toContain('Later (I-221)')
        ->and(uiComponent('components/inbox/inbox-list.tsx'))->not->toContain('Later (I-221)')
        ->and(uiComponent('components/jobs/job-progress.tsx'))->not->toContain('Later (I-104)');
});

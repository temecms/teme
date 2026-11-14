import { Search } from 'lucide-react';
import { AccountMenu } from '@/components/account-menu';
import { InboxBell } from '@/components/header/inbox-bell';
import { JobsButton } from '@/components/header/jobs-button';
import { useHeaderActivity } from '@/components/header/use-header-activity';
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';

/** Whether the keyboard's command key is ⌘, for the shortcut's hint. */
function isMac(): boolean {
    return (
        typeof navigator !== 'undefined' &&
        /Mac|iPhone|iPad/.test(navigator.userAgent)
    );
}

/**
 * The bar across the top of every admin screen: the menu button (under 768px
 * only), search on the left; the jobs button, the bell and the account menu
 * on the right. Its controls are
 * 44px on small screens.
 */
export function AppSidebarHeader() {
    const activity = useHeaderActivity();

    return (
        <header
            data-slot="app-header"
            className="bg-card border-border flex h-[52px] shrink-0 items-center gap-2 border-b px-4 max-md:pr-2"
        >
            <SidebarTrigger
                aria-label="Open menu"
                className="max-md:size-11 md:hidden"
            />
            {/* Opens the command palette, not a second search. */}
            <Button
                type="button"
                variant="outline"
                data-test="palette-button"
                aria-keyshortcuts="Control+K Meta+K"
                onClick={() => window.dispatchEvent(new Event('teme:palette'))}
                className="text-muted-foreground bg-background min-w-[260px] justify-start gap-2 px-2.5 font-normal max-md:size-11 max-md:min-w-0 max-md:justify-center max-md:p-0"
            >
                <Search aria-hidden="true" />
                <span className="flex-1 text-left max-md:sr-only">
                    Search or jump to…
                </span>
                <kbd className="border-border bg-muted rounded border px-1 text-[10px] max-md:hidden">
                    {isMac() ? '⌘K' : 'Ctrl K'}
                </kbd>
            </Button>
            <span className="flex-1" />
            <JobsButton activity={activity} />
            <InboxBell counts={activity.counts} />
            {/* Later (I-1391): a sticky bar moves the editor's sticky bars down by its own height. */}
            {/* Says when a job ends; always in the page, empty until then. */}
            <p role="status" className="sr-only">
                {activity.announcement}
            </p>
            <AccountMenu />
        </header>
    );
}

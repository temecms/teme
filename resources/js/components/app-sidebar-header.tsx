import { Link } from '@inertiajs/react';
import { ArrowLeft, Search } from 'lucide-react';
import { AccountMenu } from '@/components/account-menu';
import { InboxBell } from '@/components/header/inbox-bell';
import { JobsButton } from '@/components/header/jobs-button';
import { SiteStatus } from '@/components/header/site-status';
import { StatusSheet } from '@/components/header/status-sheet';
import { useHeaderActivity } from '@/components/header/use-header-activity';
import { Crumbs } from '@/components/patterns/crumbs';
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { __ } from '@/lib/i18n';
import { toUrl } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

/**
 * The bar across the top of every admin screen, 60 tall on the page's ground
 * (docs/architecture/design-system.md, section 4.33): the crumbs at the
 * start; the jobs, the site status, the bell and the account at the end.
 * Under 768px: the menu button, a way back to the parent, the page's name,
 * search, and one status button for jobs, the site status and the inbox.
 */
export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItem[];
}) {
    const activity = useHeaderActivity();
    const page = breadcrumbs.at(-1);
    const parent = breadcrumbs.at(-2);

    return (
        <header
            data-slot="app-header"
            className="bg-page flex h-(--top-h) shrink-0 items-center gap-2 px-6 max-md:gap-1 max-md:px-2"
        >
            <SidebarTrigger aria-label="Open menu" className="md:hidden" />
            {parent !== undefined && (
                <Button
                    variant="ghost"
                    size="icon"
                    asChild
                    className="md:hidden"
                >
                    <Link
                        href={toUrl(parent.href)}
                        aria-label={__('Back to :page', {
                            page: parent.title,
                        })}
                    >
                        <ArrowLeft aria-hidden="true" />
                    </Link>
                </Button>
            )}
            <p className="text-body min-w-0 flex-1 truncate font-semibold md:hidden">
                {page?.title}
            </p>
            {/* Opens the command palette, not a second search. */}
            <Button
                type="button"
                variant="ghost"
                size="icon"
                aria-label={__('Search or jump to…')}
                aria-keyshortcuts="Control+K Meta+K"
                onClick={() => window.dispatchEvent(new Event('teme:palette'))}
                className="md:hidden"
            >
                <Search aria-hidden="true" />
            </Button>
            <div className="flex min-w-0 flex-1 items-center max-md:hidden">
                <Crumbs trail={breadcrumbs} />
            </div>
            <div className="flex items-center gap-1 max-md:hidden">
                <JobsButton activity={activity} />
                <SiteStatus />
                <InboxBell counts={activity.counts} />
            </div>
            <div className="md:hidden">
                <StatusSheet activity={activity} />
            </div>
            {/* Later (I-1391): a sticky bar moves the editor's sticky bars down by its own height. */}
            {/* Says when a job ends; always in the page, empty until then. */}
            <p role="status" className="sr-only">
                {activity.announcement}
            </p>
            <AccountMenu />
        </header>
    );
}

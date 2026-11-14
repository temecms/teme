import { Link, usePage } from '@inertiajs/react';
import { ChevronsUpDown } from 'lucide-react';
import { Fragment } from 'react';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { __ } from '@/lib/i18n';
import { toUrl } from '@/lib/utils';
import type { BreadcrumbItem as Crumb } from '@/types';

type Sibling = { key: string; label: string; href: string };

/** Never more than section, page and item (docs/architecture/design-system.md, section 4.31). */
const MOST = 3;

const CRUMB =
    'text-body text-ink-2 hover:bg-surface-3 hover:text-ink focus-visible:outline-ring inline-flex h-8 min-w-0 items-center rounded-sm px-2 font-medium focus-visible:outline-2 focus-visible:-outline-offset-2';

/**
 * The pages beside a crumb, for its switcher: a hub's screens (Settings'
 * pages for Settings), or a section's entries when the crumb is that
 * section's first; none for any other crumb.
 */
function useSiblings(): (href: string) => Sibling[] {
    const { nav, settingsNav } = usePage().props;
    const shown = nav.filter((entry) => !entry.hidden && !entry.soon);

    return (href: string) => {
        const entry = shown.find((each) => each.href === href);

        if (entry === undefined) {
            return [];
        }

        if (entry.screens.length > 0) {
            return entry.key === 'settings'
                ? settingsNav.map(({ key, label, href: to }) => ({
                      key,
                      label,
                      href: to,
                  }))
                : entry.screens
                      .filter((screen) => !screen.external)
                      .map(({ key, label, href: to }) => ({
                          key,
                          label,
                          href: to,
                      }));
        }

        const section = shown.filter((each) => each.section === entry.section);

        return entry.section !== 'home' && section[0]?.key === entry.key
            ? section.map(({ key, label, href: to }) => ({
                  key,
                  label,
                  href: to,
              }))
            : [];
    };
}

/**
 * Where the page sits, at the start of the top bar
 * (docs/architecture/design-system.md, section 4.31): its section, page and
 * item as links, the page itself last. A crumb that is a hub or the first
 * of a section opens a menu of the pages beside it.
 */
export function Crumbs({ trail }: { trail: Crumb[] }) {
    const siblingsOf = useSiblings();
    const crumbs = trail.slice(-MOST);

    if (crumbs.length === 0) {
        return null;
    }

    return (
        <Breadcrumb
            aria-label={__('Where you are')}
            data-test="crumbs"
            className="min-w-0"
        >
            <BreadcrumbList className="flex-nowrap gap-1">
                {crumbs.map((crumb, index) => {
                    const last = index === crumbs.length - 1;
                    const href = toUrl(crumb.href);
                    const siblings = last ? [] : siblingsOf(href);

                    return (
                        <Fragment key={`${href}-${index}`}>
                            {index > 0 && <BreadcrumbSeparator />}
                            <BreadcrumbItem className="min-w-0 gap-0">
                                {last ? (
                                    <BreadcrumbPage
                                        aria-current="page"
                                        className="text-body text-ink block max-w-105 truncate px-2 font-semibold"
                                    >
                                        {crumb.title}
                                    </BreadcrumbPage>
                                ) : (
                                    <Link href={href} className={CRUMB}>
                                        <span className="truncate">
                                            {crumb.title}
                                        </span>
                                    </Link>
                                )}
                                {siblings.length > 0 && (
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon-xs"
                                                aria-label={__(
                                                    'Other pages in :section',
                                                    { section: crumb.title },
                                                )}
                                                className="h-8 w-6 pointer-coarse:size-11"
                                            >
                                                <ChevronsUpDown aria-hidden="true" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="start">
                                            {siblings.map((sibling) => (
                                                <DropdownMenuItem
                                                    key={sibling.key}
                                                    asChild
                                                >
                                                    <Link href={sibling.href}>
                                                        {sibling.label}
                                                    </Link>
                                                </DropdownMenuItem>
                                            ))}
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                )}
                            </BreadcrumbItem>
                        </Fragment>
                    );
                })}
            </BreadcrumbList>
        </Breadcrumb>
    );
}

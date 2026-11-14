import { Link } from '@inertiajs/react';
import { brandLine, useBrand } from '@/hooks/use-brand';
import { cn } from '@/lib/utils';
import { dashboard, home } from '@/routes';

// Later (I-1390): an uploaded site icon takes the letter's place.
/**
 * The site's mark: one letter on a square. In the sidebar it is the site's
 * initial in the serif on the accent (docs/architecture/design-system.md,
 * section 4.32); the sign-in screens keep theirs.
 */
export function BrandMark({
    tone,
    className,
}: {
    tone: 'sidebar' | 'screen';
    className?: string;
}) {
    const { mark } = useBrand();

    return (
        <span
            aria-hidden="true"
            data-test="brand-mark"
            className={cn(
                'flex shrink-0 items-center justify-center leading-none font-semibold',
                tone === 'sidebar'
                    ? 'bg-accent text-on-accent text-lead size-9 rounded-md font-serif'
                    : 'bg-ink text-page size-10 rounded-md text-lg',
                className,
            )}
        >
            {mark}
        </span>
    );
}

/**
 * The sidebar's head: the mark, the site's name and the line under it. While
 * the sidebar is folded the words are for screen readers only, so the link
 * keeps its name.
 */
export function SidebarBrand() {
    const brand = useBrand();

    return (
        <Link
            href={dashboard()}
            prefetch
            data-test="sidebar-brand"
            className="focus-visible:outline-ring flex min-h-17 items-center gap-3 px-4 pt-4 pb-3 outline-none group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:px-0 focus-visible:outline-2 focus-visible:-outline-offset-2"
        >
            <BrandMark tone="sidebar" />
            <span className="grid min-w-0 flex-1 text-start group-data-[collapsible=icon]:sr-only">
                <span className="text-body text-ink truncate font-semibold">
                    {brand.name}
                </span>
                <span className="text-caption text-ink-3 truncate">
                    {brandLine(brand)}
                </span>
            </span>
        </Link>
    );
}

/**
 * The sign-in screens' head: the same mark, larger, over the title. `name`
 * replaces the site's name where the site has none yet, the setup page.
 */
export function SignInBrand({ name }: { name?: string } = {}) {
    const brand = useBrand();

    return (
        <Link
            href={home()}
            data-test="sign-in-brand"
            className="focus-visible:ring-ring flex flex-col items-center gap-1.5 rounded-md outline-none focus-visible:ring-2"
        >
            <BrandMark tone="screen" />
            <span className="text-[15px] font-semibold">
                {name ?? brand.name}
            </span>
            <span className="text-ink-2 text-[11px]">{brandLine(brand)}</span>
        </Link>
    );
}

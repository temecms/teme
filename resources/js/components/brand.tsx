import { Link } from '@inertiajs/react';
import { brandLine, useBrand } from '@/hooks/use-brand';
import { cn } from '@/lib/utils';
import { dashboard, home } from '@/routes';

// Later (I-1390): an uploaded site icon takes the letter's place.
/**
 * The site's mark: one letter in the sans face, on a square. The serif is
 * for the writing only, never for the admin's chrome.
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
                    ? 'bg-sidebar-foreground text-sidebar size-6 rounded-md text-[13px]'
                    : 'bg-foreground text-background size-10 rounded-md text-lg',
                className,
            )}
        >
            {mark}
        </span>
    );
}

/**
 * The sidebar's head: the mark, the site's name and the line under it, 52px
 * tall so its rule meets the header's. While the sidebar is collapsed the
 * words are for screen readers only, so the link keeps its name.
 */
export function SidebarBrand() {
    const brand = useBrand();

    return (
        <Link
            href={dashboard()}
            prefetch
            data-test="sidebar-brand"
            className="focus-visible:ring-sidebar-ring flex h-[52px] items-center gap-2.5 px-4 outline-none group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:px-0 focus-visible:ring-2 focus-visible:ring-inset"
        >
            <BrandMark tone="sidebar" />
            <span className="grid min-w-0 flex-1 text-left leading-tight group-data-[collapsible=icon]:sr-only">
                <span className="truncate font-semibold">{brand.name}</span>
                <span className="text-sidebar-muted truncate text-[11px]">
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
            <span className="text-muted-foreground text-[11px]">
                {brandLine(brand)}
            </span>
        </Link>
    );
}

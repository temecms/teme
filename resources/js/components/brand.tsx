import { Link } from '@inertiajs/react';
import { brandLine, useBrand } from '@/hooks/use-brand';
import { cn } from '@/lib/utils';
import { dashboard, home } from '@/routes';

// Later (I-1390): an uploaded site icon takes the letter's place.
/**
 * The site's mark: its initial in the serif on the accent, one square, in
 * the sidebar and at the head of the sign-in card
 * (docs/architecture/design-system.md, section 4.32).
 */
export function BrandMark({
    className,
}: {
    /** Where it is drawn; the sidebar's square is the only one. */
    tone: 'sidebar';
    className?: string;
}) {
    const { mark } = useBrand();

    return (
        <span
            aria-hidden="true"
            data-test="brand-mark"
            className={cn(
                'bg-accent text-on-accent text-lead flex size-9 shrink-0 items-center justify-center rounded-md font-serif leading-none font-semibold',
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
 * The sign-in card's head: the site's mark, its name and the line under it,
 * a link to the site. Teme's own mark and name stand in where the site has
 * no name yet: the setup page, or a site whose title is empty.
 */
export function SignInBrand({ teme = false }: { teme?: boolean } = {}) {
    const brand = useBrand();
    const asTeme = teme || brand.name.trim() === '';

    return (
        <Link
            href={home()}
            data-test="sign-in-brand"
            className="focus-visible:outline-ring flex items-center gap-3 justify-self-start rounded-md outline-none focus-visible:outline-2 focus-visible:outline-offset-2"
        >
            {asTeme ? (
                <img
                    src="/teme/icon.svg"
                    alt=""
                    width={36}
                    height={36}
                    className="size-9 shrink-0"
                />
            ) : (
                <BrandMark tone="sidebar" />
            )}
            <span className="grid min-w-0 text-start">
                <span className="text-body text-ink font-semibold">
                    {asTeme ? brand.product : brand.name}
                </span>
                <span className="text-caption text-ink-3">
                    {brandLine(brand, asTeme)}
                </span>
            </span>
        </Link>
    );
}

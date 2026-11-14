import { Link } from '@inertiajs/react';
import { Fragment } from 'react';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

/**
 * The parents of the screen below, above its title: "Tools ›" over
 * "Redirects". The last item of a trail is the screen itself, which its title
 * already names, so a trail of one draws nothing.
 */
export function PageTrail({
    breadcrumbs,
}: {
    breadcrumbs: BreadcrumbItemType[];
}) {
    const parents = breadcrumbs.slice(0, -1);

    if (parents.length === 0) {
        return null;
    }

    return (
        <Breadcrumb
            aria-label="Breadcrumb"
            data-test="page-trail"
            className="px-6 pt-4 max-md:px-4"
        >
            <BreadcrumbList className="text-muted-foreground gap-1.5 text-xs sm:gap-1.5">
                {parents.map((item, index) => (
                    <Fragment key={index}>
                        <BreadcrumbItem>
                            <BreadcrumbLink asChild>
                                <Link href={item.href}>{item.title}</Link>
                            </BreadcrumbLink>
                        </BreadcrumbItem>
                        <BreadcrumbSeparator />
                    </Fragment>
                ))}
            </BreadcrumbList>
        </Breadcrumb>
    );
}

import type { ReactNode } from 'react';
import { Page } from '@/components/patterns/page';
import { PageHeader } from '@/components/patterns/page-header';

/**
 * A list page (docs/architecture/design-system.md, section 5): the page
 * header, then the views, the filters, the bulk bar and the table frame the
 * page passes in.
 */
export function ListPage({
    title,
    description,
    actions,
    plugin,
    badge,
    titleAs,
    children,
}: {
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
    plugin?: string;
    /** Beside the title, such as a Soon tag. */
    badge?: ReactNode;
    titleAs?: 'h1' | 'div';
    children: ReactNode;
}) {
    return (
        <Page>
            <PageHeader
                title={title}
                description={description}
                actions={actions}
                plugin={plugin}
                badge={badge}
                as={titleAs}
            />
            <div className="grid gap-4">{children}</div>
        </Page>
    );
}

import type { ReactNode } from 'react';
import { Page } from '@/components/patterns/page';
import { PageHeader } from '@/components/patterns/page-header';

/**
 * Home's page pattern (docs/architecture/design-system.md, section 5): the
 * page frame, its header (greeting, description, actions), then the figures
 * and panels the page draws.
 */
export function DashboardPage({
    title,
    description,
    actions,
    children,
}: {
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
    children: ReactNode;
}) {
    return (
        <Page>
            <PageHeader
                title={title}
                description={description}
                actions={actions}
            />
            {children}
        </Page>
    );
}

import type { ReactNode } from 'react';
import { Page } from '@/components/patterns/page';
import { PageHeader } from '@/components/patterns/page-header';

/**
 * A form page (docs/architecture/design-system.md, sections 4.12 and 5): the
 * page header over the 760 column, left-aligned. Its form's last child is
 * the SaveBar.
 */
export function FormPage({
    title,
    description,
    actions,
    titleAs,
    children,
}: {
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
    titleAs?: 'h1' | 'div';
    children: ReactNode;
}) {
    return (
        <Page width="form">
            <PageHeader
                title={title}
                description={description}
                actions={actions}
                as={titleAs}
            />
            {children}
        </Page>
    );
}

/**
 * One group of a form's fields (docs/architecture/design-system.md,
 * section 4.12): a raised panel with a title and an optional line, its
 * fields 20 apart; panels sit 16 apart.
 */
export function FormPanel({
    title,
    description,
    children,
}: {
    /** A panel's heading; a form of one panel may go without, its page's title naming it. */
    title?: string;
    description?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section
            data-slot="form-panel"
            className="bg-surface shadow-raised grid gap-5 rounded-lg p-6 [&+&]:mt-4"
        >
            {title !== undefined && (
                <div className="grid gap-1">
                    <h2 className="text-lead text-ink font-semibold">
                        {title}
                    </h2>
                    {description && (
                        <p className="text-body text-ink-2">{description}</p>
                    )}
                </div>
            )}
            {children}
        </section>
    );
}

import type { CSSProperties, ReactNode } from 'react';
import { Page } from '@/components/patterns/page';
import { PageHeader } from '@/components/patterns/page-header';

/** The content and the 360 side panel (docs/architecture/design-system.md, section 5). */
const COLUMNS = {
    '--detail-cols': 'minmax(0, 1fr) var(--detail-w)',
} as CSSProperties;

/**
 * A detail page with a side panel (docs/architecture/design-system.md,
 * section 5): the page header, then the content beside a panel that stays
 * in view as the page scrolls; under 1024px the panel goes under. With no
 * panel the content takes the whole width; `lead` spans both, above them.
 * `back`, a way back to where the page was opened from, sits above the title.
 */
export function DetailPage({
    title,
    description,
    actions,
    panel,
    panelLabel,
    titleAs,
    badge,
    showTitle,
    lead,
    back,
    children,
}: {
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
    panel: ReactNode;
    /** The side panel's name, for its landmark. */
    panelLabel: string;
    titleAs?: 'h1' | 'div';
    /** Drawn after the title: a "Soon" tag. */
    badge?: ReactNode;
    /** Keep the large title when it is not the breadcrumb's last crumb. */
    showTitle?: boolean;
    /** Above the content and the panel, across both: a filter row. */
    lead?: ReactNode;
    /** A link back, above the title: "← Back to the editor". */
    back?: ReactNode;
    children: ReactNode;
}) {
    return (
        <Page>
            {back !== undefined && <div className="mt-2">{back}</div>}
            <PageHeader
                title={title}
                description={description}
                actions={actions}
                as={titleAs}
                badge={badge}
                showTitle={showTitle}
            />
            {lead}
            <div
                className={
                    panel === null || panel === undefined
                        ? 'grid gap-6'
                        : 'grid gap-6 lg:grid-cols-(--detail-cols)'
                }
                style={COLUMNS}
            >
                <div className="min-w-0">{children}</div>
                {panel !== null && panel !== undefined && (
                    <section
                        aria-label={panelLabel}
                        className="min-w-0 lg:sticky lg:top-4 lg:self-start"
                    >
                        {panel}
                    </section>
                )}
            </div>
        </Page>
    );
}

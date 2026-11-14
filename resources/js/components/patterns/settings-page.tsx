import type { ReactNode } from 'react';
import { PageHeader } from '@/components/patterns/page-header';

/**
 * A settings page (docs/architecture/design-system.md, sections 4.42 and 5):
 * its header and sections in the form's column, inside the settings frame
 * (layouts/settings/layout.tsx), which owns the page's container. A page
 * with a form ends it with the SaveBar.
 */
export function SettingsPage({
    title,
    description,
    titleAs,
    children,
}: {
    title: string;
    description?: ReactNode;
    titleAs?: 'h1' | 'div';
    children?: ReactNode;
}) {
    return (
        <div data-slot="settings-page" className="max-w-(--form-max)">
            <PageHeader title={title} description={description} as={titleAs} />
            {children}
        </div>
    );
}

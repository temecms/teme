import type { ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { PageHeader } from '@/components/patterns/page-header';
import { useSettingsFrame } from '@/components/patterns/settings-frame';
import { StatusPill } from '@/components/ui/status-pill';
import { __ } from '@/lib/i18n';

/**
 * A settings page (docs/architecture/design-system.md, sections 4.42 and 5).
 * Inside the Settings frame (layouts/settings/layout.tsx) its header is drawn
 * across the frame's full width, above the side column, with "Saved just
 * now" after a save; its sections stay in the form's column. Elsewhere, as
 * on the account's pages, the header heads the column. A page with a form
 * ends it with the SaveBar.
 */
export function SettingsPage({
    title,
    description,
    titleAs,
    actions,
    plugin,
    children,
}: {
    title: string;
    description?: ReactNode;
    titleAs?: 'h1' | 'div';
    /** The header's buttons. */
    actions?: ReactNode;
    /** The plugin whose settings these are: its mark follows the title. */
    plugin?: string;
    children?: ReactNode;
}) {
    const frame = useSettingsFrame();
    const saved = frame?.saved === true && (
        <StatusPill tone="ok" role="status" data-test="settings-saved">
            {__('Saved just now')}
        </StatusPill>
    );
    const header = (
        <PageHeader
            title={title}
            description={description}
            plugin={plugin}
            actions={
                actions || saved ? (
                    <>
                        {saved}
                        {actions}
                    </>
                ) : undefined
            }
            as={titleAs}
        />
    );

    if (frame === null) {
        return (
            <div data-slot="settings-page" className="max-w-(--form-max)">
                {header}
                {children}
            </div>
        );
    }

    return (
        <div
            data-slot="settings-page"
            className="grid max-w-(--form-max) gap-4"
        >
            {frame.head !== null && createPortal(header, frame.head)}
            {children}
        </div>
    );
}

import { useId } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    /** The panel's heading; none draws a panel with no header. */
    title?: string;
    /** The heading's one muted line. */
    description?: string;
    /** A form's fields are a fieldset; a panel with none is a section. */
    as?: 'fieldset' | 'section';
    /** A Save of the panel's own, in its footer. */
    footer?: ReactNode;
    /** Names a panel that has no heading. */
    label?: string;
    id?: string;
    className?: string;
    /** Whether the children draw their own body (a form holding body and footer). */
    bare?: boolean;
    children: ReactNode;
};

const headClass = 'grid gap-1 px-6 pt-6 max-sm:px-4 max-sm:pt-4';

/** The panel's body, its fields 20px apart; for a form that holds a body and a footer. */
export function SettingsPanelBody({
    className,
    children,
}: {
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={cn('grid gap-5 p-6 max-sm:p-4', className)}>
            {children}
        </div>
    );
}

/** The panel's footer, for a Save of its own (I-3128). */
export function SettingsPanelFooter({ children }: { children: ReactNode }) {
    return (
        <div
            data-test="panel-save"
            className="border-line flex flex-wrap items-center gap-x-3 gap-y-2 border-t px-6 py-3 max-sm:px-4"
        >
            {children}
        </div>
    );
}

/**
 * A titled panel of a Settings page, drawn as a form panel
 * (docs/architecture/design-system.md, 4.12): a header, a body of fields
 * 20px apart, and a footer only where the panel saves on its own.
 */
export function SettingsPanel({
    title,
    description,
    as = 'fieldset',
    footer,
    label,
    id,
    className,
    bare = false,
    children,
}: Props) {
    const headingId = useId();
    const lineId = useId();
    const heading = title !== undefined && (
        <>
            <h2
                id={headingId}
                className="text-lead text-ink font-semibold text-balance"
            >
                {title}
            </h2>
            {description !== undefined && (
                <p
                    id={lineId}
                    data-test="settings-panel-description"
                    className="text-body text-ink-2 text-pretty"
                >
                    {description}
                </p>
            )}
        </>
    );
    const body = bare ? (
        children
    ) : (
        <SettingsPanelBody className="clear-both">{children}</SettingsPanelBody>
    );
    const foot = footer !== undefined && (
        <SettingsPanelFooter>{footer}</SettingsPanelFooter>
    );
    const common = {
        id,
        'data-test': 'settings-panel',
        className: cn('bg-surface shadow-raised min-w-0 rounded-lg', className),
    };

    if (as === 'section') {
        return (
            <section
                {...common}
                aria-labelledby={title !== undefined ? headingId : undefined}
                aria-label={title === undefined ? label : undefined}
            >
                {title !== undefined && (
                    <div className={headClass}>{heading}</div>
                )}
                {body}
                {foot}
            </section>
        );
    }

    return (
        <fieldset
            {...common}
            aria-describedby={description !== undefined ? lineId : undefined}
            aria-label={title === undefined ? label : undefined}
        >
            {title !== undefined && (
                <legend className={cn(headClass, 'float-left w-full')}>
                    {heading}
                </legend>
            )}
            {body}
            {foot}
        </fieldset>
    );
}

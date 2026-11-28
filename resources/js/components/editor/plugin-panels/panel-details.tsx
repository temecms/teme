import { ImageIcon, TriangleAlertIcon } from 'lucide-react';
import { StatusPill } from '@/components/ui/status-pill';
import { adminLocale, siteZone } from '@/lib/dates';
import { __ } from '@/lib/i18n';
import type { PanelDetailRow, PanelPreviewCard, PanelRowValue } from '@/types';

/** A date and time in the admin's language, in the site's zone, as a time element. */
function DateValue({ iso }: { iso: string }) {
    const text = new Intl.DateTimeFormat(adminLocale(), {
        timeZone: siteZone(),
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(iso));

    return <time dateTime={iso}>{text}</time>;
}

/** A value as the pill's text: plain text, or a date and time in the same form as DateValue. */
function valueText(value: PanelRowValue): string {
    if (typeof value === 'string') {
        return value;
    }

    return new Intl.DateTimeFormat(adminLocale(), {
        timeZone: siteZone(),
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value.iso));
}

/**
 * The plugin's labelled lines: a label on the left, its value on the right,
 * a pill when the row has a tone. Every value is plain text.
 */
export function PanelRows({
    heading,
    rows,
    tool,
}: {
    heading: string;
    rows: PanelDetailRow[];
    tool: string;
}) {
    return (
        <section className="grid gap-3" aria-labelledby={`${tool}-details`}>
            <h3 id={`${tool}-details`} className="text-ink font-semibold">
                {heading}
            </h3>
            <dl
                className="grid grid-cols-2 gap-x-4 gap-y-2"
                data-test="plugin-panel-rows"
            >
                {rows.map((row, index) => (
                    <div key={index} className="contents">
                        <dt className="text-ink-2">{row.label}</dt>
                        <dd className="text-ink min-w-0 text-right break-words">
                            {row.tone !== null ? (
                                <StatusPill tone={row.tone}>
                                    {valueText(row.value)}
                                </StatusPill>
                            ) : typeof row.value === 'string' ? (
                                row.value
                            ) : (
                                <DateValue iso={row.value.iso} />
                            )}
                        </dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}

/**
 * The generic link card: a picture on top at 1.91 to 1, the site's host,
 * the title and the description. Core draws it the same for every plugin;
 * the note says it shows the saved draft, or that the editor has changes.
 */
export function PanelPreview({
    tool,
    plugin,
    preview,
    unsaved,
}: {
    tool: string;
    plugin: string;
    preview: PanelPreviewCard;
    /** The editor has changes not yet saved; the card still shows the saved draft. */
    unsaved: boolean;
}) {
    return (
        <section className="grid gap-3" aria-labelledby={`${tool}-preview`}>
            <h3 id={`${tool}-preview`} className="text-ink font-semibold">
                {__('How it will look')}
            </h3>
            <figure
                className="border-line bg-surface grid overflow-hidden rounded-md border"
                aria-label={__('Preview of what :plugin will send', { plugin })}
                data-test="plugin-panel-preview"
            >
                {preview.picture !== null ? (
                    <img
                        src={preview.picture}
                        alt={preview.title}
                        className="aspect-[191/100] w-full object-cover"
                    />
                ) : (
                    <div className="text-ink-2 bg-surface-2 flex items-center gap-2 p-3">
                        <ImageIcon
                            className="size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span>
                            {__(
                                'This article has no featured image, so the card shows none.',
                            )}
                        </span>
                    </div>
                )}
                <figcaption className="grid gap-1 p-3">
                    {preview.host !== null && (
                        <span className="text-ink-2 text-small truncate">
                            {preview.host}
                        </span>
                    )}
                    <span className="text-ink line-clamp-2 font-semibold">
                        {preview.title}
                    </span>
                    {preview.description !== '' && (
                        <span className="text-ink-2 line-clamp-2">
                            {preview.description}
                        </span>
                    )}
                </figcaption>
            </figure>
            {unsaved ? (
                <p
                    role="status"
                    aria-live="polite"
                    data-test="plugin-panel-unsaved"
                    className="text-warn-ink text-small flex items-start gap-2"
                >
                    <TriangleAlertIcon
                        className="size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <span>
                        {__(
                            'You have unsaved changes. The card shows the draft as you last saved it; save to see them here.',
                        )}
                    </span>
                </p>
            ) : (
                <p className="text-ink-2 text-small">
                    {__(
                        'Shows the draft as you last saved it. It looks different on each network; this is the shape they share.',
                    )}
                </p>
            )}
        </section>
    );
}

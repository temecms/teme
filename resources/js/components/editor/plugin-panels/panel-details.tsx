import { ImageIcon } from 'lucide-react';
import { StatusPill } from '@/components/ui/status-pill';
import { adminLocale } from '@/lib/dates';
import { __ } from '@/lib/i18n';
import type { PanelDetailRow, PanelPreviewCard, PanelRowValue } from '@/types';

/** A date and time as the admin's language shows it, in a time element. */
function DateValue({ iso }: { iso: string }) {
    const text = new Intl.DateTimeFormat(adminLocale(), {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(iso));

    return <time dateTime={iso}>{text}</time>;
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

/** A value as text, for a pill. */
function valueText(value: PanelRowValue): string {
    return typeof value === 'string'
        ? value
        : new Date(value.iso).toLocaleString();
}

/**
 * The generic link card: a picture on top at about 1.91 to 1, the site's
 * host, the title and the description. Core draws it the same for every
 * plugin; the note says it shows the saved draft.
 */
export function PanelPreview({
    tool,
    plugin,
    preview,
}: {
    tool: string;
    plugin: string;
    preview: PanelPreviewCard;
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
                        className="aspect-video w-full object-cover"
                    />
                ) : (
                    <div className="text-ink-2 bg-surface-2 flex aspect-auto items-center gap-2 p-3">
                        <ImageIcon
                            className="size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span>
                            {__(
                                'This item has no featured image, so the card shows none.',
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
            <p className="text-ink-2 text-small">
                {__(
                    'Shows the draft as you last saved it. It looks different on each network; this is the shape they share.',
                )}
            </p>
        </section>
    );
}

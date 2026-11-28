import { ExternalLink } from 'lucide-react';
import { StatusPill } from '@/components/ui/status-pill';
import { __ } from '@/lib/i18n';
import type { PluginCellValue } from '@/types/plugin-columns';

/**
 * One cell of a plugin list column: plain text, a tabular number, a Status
 * pill, an outside link (area tables only), or an empty dash. Never
 * interprets markup.
 */
export function PluginCell({ value }: { value: PluginCellValue | undefined }) {
    if (value === undefined) {
        return (
            <>
                <span aria-hidden="true">—</span>
                <span className="sr-only">{__('No value')}</span>
            </>
        );
    }

    if (value.kind === 'number') {
        return (
            <span className="block truncate">
                {new Intl.NumberFormat().format(value.value)}
            </span>
        );
    }

    if (value.kind === 'badge') {
        return <StatusPill tone={value.tone}>{value.value}</StatusPill>;
    }

    if (value.kind === 'link') {
        if (value.href === null || value.host === null) {
            return (
                <span className="text-ink-3 text-small">{__('No report link')}</span>
            );
        }

        return (
            <span className="grid gap-0.5">
                <a
                    href={value.href}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-accent-ink inline-flex items-center gap-1 font-medium underline-offset-2 hover:underline"
                >
                    <span className="truncate">{value.value}</span>
                    <ExternalLink aria-hidden="true" className="size-3.5 shrink-0" />
                    <span className="sr-only">
                        {__('(opens :host in a new tab and leaves this site)', {
                            host: value.host,
                        })}
                    </span>
                </a>
                <span className="text-ink-3 text-small truncate" aria-hidden="true">
                    {value.host}
                </span>
            </span>
        );
    }

    return <span className="block truncate">{value.value}</span>;
}

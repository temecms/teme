import { StatusPill } from '@/components/ui/status-pill';
import { __ } from '@/lib/i18n';
import type { PluginCellValue } from '@/types/plugin-columns';

/**
 * One cell of a plugin list column: plain text, a tabular number, a Status
 * pill, or an empty dash. Never interprets markup.
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

    return <span className="block truncate">{value.value}</span>;
}

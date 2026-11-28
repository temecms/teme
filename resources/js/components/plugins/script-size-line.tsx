import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * How big a plugin's declared scripts are, compressed, in a line for its row or its consent dialog.
 * It warns past 20 KB and never blocks; null when the plugin declares none.
 */
export function ScriptSizeLine({
    scripts,
    testId,
}: {
    scripts: { bytes: number; over: boolean } | null;
    testId: string;
}) {
    if (scripts === null) {
        return null;
    }

    const size = (scripts.bytes / 1024).toFixed(1);

    return (
        <p
            data-test={testId}
            className={cn(
                'text-small',
                scripts.over ? 'text-warn-ink' : 'text-ink-2',
            )}
        >
            {scripts.over
                ? __(
                      'Its scripts are :size KB compressed, over the 20 KB budget. They still run.',
                      { size },
                  )
                : __('Its scripts are :size KB compressed.', { size })}
        </p>
    );
}

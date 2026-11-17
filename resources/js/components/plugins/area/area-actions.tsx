import { router } from '@inertiajs/react';
import { useState } from 'react';
import PluginAreaController from '@/actions/App/Http/Admin/PluginAreaController';
import { Button } from '@/components/ui/button';
import { Panel } from '@/components/ui/panel';
import { __ } from '@/lib/i18n';
import type { AreaActionsBlock, AreaPluginRef } from '@/types/plugin-area';

/**
 * A plugin's actions in its area (docs/plans/plugin-sockets-2.md, A6): each
 * a button that queues the job the plugin declared for it, as the person
 * who clicked, with its line beside it.
 */
export function AreaActions({
    plugin,
    block,
}: {
    plugin: AreaPluginRef;
    block: AreaActionsBlock;
}) {
    const [running, setRunning] = useState<string | null>(null);

    if (block.actions.length === 0) {
        return null;
    }

    return (
        <Panel data-test="area-actions" aria-label={__('Actions')}>
            <ul className="divide-line grid divide-y">
                {block.actions.map((action) => (
                    <li
                        key={action.id}
                        className="flex flex-wrap items-center justify-between gap-3 px-5 py-4"
                    >
                        <div className="grid gap-0.5">
                            <span className="text-body font-medium">
                                {action.label}
                            </span>
                            {action.description !== null && (
                                <span className="text-small text-ink-2">
                                    {action.description}
                                </span>
                            )}
                        </div>
                        <Button
                            type="button"
                            variant="neutral"
                            loading={running === action.id}
                            data-test={`area-action-${action.id}`}
                            onClick={() =>
                                router.post(
                                    PluginAreaController.action.url({
                                        code: plugin.code,
                                        action: action.id,
                                    }),
                                    {},
                                    {
                                        preserveScroll: true,
                                        onStart: () => setRunning(action.id),
                                        onFinish: () => setRunning(null),
                                    },
                                )
                            }
                        >
                            {action.label}
                        </Button>
                    </li>
                ))}
            </ul>
        </Panel>
    );
}

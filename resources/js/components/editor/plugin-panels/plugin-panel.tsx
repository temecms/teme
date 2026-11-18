import {
    ChartLine,
    ExternalLink,
    Link as LinkIcon,
    ListChecks,
    PenLine,
    Plug,
    RefreshCw,
    Send,
    Settings,
    Sparkles,
    Bell,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import EditorPanelController from '@/actions/App/Http/Admin/Contents/EditorPanelController';
import { send } from '@/components/editor/editor-http';
import { ErrorState } from '@/components/ui/error-state';
import { Notice } from '@/components/ui/notice';
import { Progress } from '@/components/ui/progress';
import { Skeleton } from '@/components/ui/skeleton';
import { __ } from '@/lib/i18n';
import type { EditorPluginPanel, PanelAnswer, PanelJob } from '@/types';
import { PANEL_WORDS } from './panel-words';
import { PanelActionForm } from './panel-action-form';
import { PanelSuggestions } from './panel-suggestions';
import type { PanelApply } from './panel-suggestions';

/** A panel's icon, from the closed list its manifest chooses from. */
export const PANEL_ICONS: Record<string, LucideIcon> = {
    plug: Plug,
    link: LinkIcon,
    'external-link': ExternalLink,
    settings: Settings,
    send: Send,
    bell: Bell,
    'refresh-cw': RefreshCw,
    'chart-line': ChartLine,
    sparkles: Sparkles,
    'pen-line': PenLine,
    'list-checks': ListChecks,
};

/** How often the panel asks again while its work waits or runs, and for how long at most. */
const POLL_MS = 5_000;
const POLL_FOR_MS = 15 * 60_000;

/**
 * A plugin's panel in the editor's rail: its status line, what it can be
 * asked to do, its work on this item with the progress, and the changes it
 * suggests, each used or discarded by the person. Core draws all of it;
 * every word the plugin sends is shown as text.
 */
export function PluginPanel({
    panel,
    contentId,
    apply,
}: {
    panel: EditorPluginPanel;
    contentId: string;
    apply: PanelApply;
}) {
    const [answer, setAnswer] = useState<PanelAnswer | null>(null);
    const [problem, setProblem] = useState<string | null>(null);
    const startedPolling = useRef<number | null>(null);
    const params = { content: contentId, code: panel.code, panel: panel.id };

    const read = useCallback(async () => {
        try {
            const got = await send(EditorPanelController.show(params));

            if (got.status === 200) {
                setAnswer(got.body as unknown as PanelAnswer);
                setProblem(null);
            } else {
                setProblem(__(PANEL_WORDS.unexpected));
            }
        } catch {
            setProblem(__(PANEL_WORDS.offline));
        }
        // The params are the panel's and the item's, fixed while it is open.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [contentId, panel.code, panel.id]);

    useEffect(() => {
        void read();
    }, [read]);

    // While its work waits or runs, ask again every 5 seconds for at most
    // 15 minutes; closing the pane unmounts it, which stops asking.
    const busy = answer?.busy === true;

    useEffect(() => {
        if (!busy) {
            startedPolling.current = null;

            return;
        }

        startedPolling.current ??= Date.now();
        const timer = window.setInterval(() => {
            if (
                startedPolling.current !== null &&
                Date.now() - startedPolling.current > POLL_FOR_MS
            ) {
                window.clearInterval(timer);

                return;
            }

            void read();
        }, POLL_MS);

        return () => window.clearInterval(timer);
    }, [busy, read]);

    return (
        <div className="text-small grid gap-5" data-test="plugin-panel">
            {panel.description !== '' && (
                <p className="text-ink-2">{panel.description}</p>
            )}

            {answer === null && problem === null && (
                <div className="grid gap-2" aria-busy="true">
                    <Skeleton className="h-4 w-2/3" />
                    <Skeleton className="h-4 w-1/2" />
                </div>
            )}

            {problem !== null && (
                <Notice tone="danger" role="alert">
                    {problem}
                </Notice>
            )}

            {answer?.failed != null && (
                <ErrorState
                    data-test="plugin-panel-failed"
                    message={answer.failed}
                    onRetry={() => void read()}
                    className="px-0 pt-0 pb-0"
                />
            )}

            {answer?.failed === null && answer.ai === 'off' && (
                <Notice
                    tone="info"
                    data-test="plugin-panel-ai-off"
                    title={__('AI switched off for :plugin', {
                        plugin: panel.plugin,
                    })}
                >
                    {__(
                        'An administrator switched it off in Settings › AI. The rest of :plugin still works.',
                        { plugin: panel.plugin },
                    )}
                </Notice>
            )}

            {answer?.failed === null && answer.ai === 'budget' && (
                <Notice
                    tone="warn"
                    data-test="plugin-panel-ai-budget"
                    title={__(':plugin’s budget used up', {
                        plugin: panel.plugin,
                    })}
                >
                    {__(
                        'Its AI budget for this month is spent. It can ask AI again when the month ends.',
                    )}
                </Notice>
            )}

            {answer !== null &&
                answer.failed === null &&
                answer.status !== null &&
                answer.status !== '' && (
                    <p
                        data-test="plugin-panel-status"
                        data-tone={answer.tone}
                        className={toneClass(answer.tone)}
                    >
                        {answer.status}
                    </p>
                )}

            {panel.actions.length > 0 &&
                answer?.failed == null &&
                (answer?.ai ?? null) === null && (
                    <section
                        className="grid gap-3"
                        aria-labelledby={`${panel.tool}-ask`}
                    >
                        <h3
                            id={`${panel.tool}-ask`}
                            className="text-ink font-semibold"
                        >
                            {__('Ask')}
                        </h3>
                        {panel.actions.map((action) => (
                            <PanelActionForm
                                key={action.id}
                                action={action}
                                panel={panel}
                                contentId={contentId}
                                busy={busy}
                                checkpoint={apply.checkpoint}
                                onStale={apply.markStale}
                                onAnswer={(next) => setAnswer(next)}
                            />
                        ))}
                    </section>
                )}

            {answer !== null && answer.jobs.length > 0 && (
                <section
                    className="grid gap-3"
                    aria-labelledby={`${panel.tool}-progress`}
                >
                    <h3
                        id={`${panel.tool}-progress`}
                        className="text-ink font-semibold"
                    >
                        {__('Progress')}
                    </h3>
                    <ul className="grid gap-3" data-test="plugin-panel-jobs">
                        {answer.jobs.map((job) => (
                            <JobLine key={job.id} job={job} />
                        ))}
                    </ul>
                </section>
            )}

            {answer !== null && answer.suggestions.length > 0 && (
                <section
                    className="grid gap-3"
                    aria-labelledby={`${panel.tool}-suggestions`}
                >
                    <h3
                        id={`${panel.tool}-suggestions`}
                        className="text-ink font-semibold"
                    >
                        {__('Suggestions')}
                    </h3>
                    <PanelSuggestions
                        panel={panel}
                        contentId={contentId}
                        suggestions={answer.suggestions}
                        apply={apply}
                        onAnswer={(next) => setAnswer(next)}
                    />
                </section>
            )}

            {answer !== null &&
                answer.failed === null &&
                answer.suggestions.length === 0 &&
                answer.jobs.length === 0 && (
                    <p className="text-ink-2">
                        {__(
                            'Suggestions appear here. Nothing changes until you choose Use, and using one is a change you can undo.',
                        )}
                    </p>
                )}
        </div>
    );
}

/** One job's line: its label, its state or progress, and its message. */
function JobLine({ job }: { job: PanelJob }) {
    const state: Record<PanelJob['state'], string> = {
        waiting: __('Waiting for a free worker'),
        running: __('Running'),
        finished: __('Done'),
        failed: __('Failed'),
        cancelled: __('Cancelled'),
    };

    return (
        <li
            className="grid gap-2"
            data-test="plugin-panel-job"
            data-state={job.state}
        >
            <div className="flex items-baseline justify-between gap-3">
                <span className="text-ink min-w-0 truncate">{job.label}</span>
                <span className="text-ink-2 shrink-0">
                    {job.state === 'running' &&
                    job.total !== null &&
                    job.total > 0
                        ? __(':done of :total', {
                              done: job.done,
                              total: job.total,
                          })
                        : state[job.state]}
                </span>
            </div>
            {job.state === 'running' && job.total !== null && job.total > 0 && (
                <Progress value={job.done} max={job.total} label={job.label} />
            )}
            {job.message !== null && job.message !== '' && (
                <span
                    className={
                        job.state === 'failed'
                            ? 'text-danger-ink'
                            : 'text-ink-2'
                    }
                >
                    {job.message}
                </span>
            )}
        </li>
    );
}

/** The status line's ink for its tone. */
function toneClass(tone: PanelAnswer['tone']): string {
    return {
        ok: 'text-ok-ink',
        info: 'text-info-ink',
        warn: 'text-warn-ink',
        danger: 'text-danger-ink',
        neutral: 'text-ink-2',
    }[tone];
}

import { CheckCircle2, CircleAlert, CircleX } from 'lucide-react';
import { PluginMark } from '@/components/ui/plugin-mark';
import { TextButton } from '@/components/ui/text-button';
import { __ } from '@/lib/i18n';
import type { PluginCheckGroup, PluginCheckResult } from '@/types/editor';

function StateIcon({ state }: { state: PluginCheckResult['state'] }) {
    if (state === 'ok') {
        return (
            <CheckCircle2 aria-hidden className="text-ok-ink size-4 shrink-0" />
        );
    }

    return state === 'problem' ? (
        <CircleX aria-hidden className="text-danger-ink size-4 shrink-0" />
    ) : (
        <CircleAlert aria-hidden className="text-warn-ink size-4 shrink-0" />
    );
}

/** The state in words, for a screen reader. */
function stateWords(state: PluginCheckResult['state']): string {
    switch (state) {
        case 'ok':
            return __('passed');
        case 'problem':
            return __('problem');
        default:
            return __('could be improved');
    }
}

/**
 * The plugins' checks, each plugin's under its own name
 * (docs/guides/developers/plugins/seo.md): plain facts drawn by core, a
 * result that names a field a button that goes there. Like core's, they
 * warn and never block.
 */
export function PluginChecks({
    groups,
    onGoTo,
    testId,
}: {
    groups: PluginCheckGroup[];
    /** Puts the cursor on a field the result names; absent where nothing can. */
    onGoTo?: (code: string, result: PluginCheckResult) => void;
    testId: string;
}) {
    if (groups.length === 0) {
        return null;
    }

    return (
        <div data-test={testId} className="grid gap-4">
            {groups.map((group) => (
                <section
                    key={group.code}
                    aria-label={__('Checks from :name', { name: group.plugin })}
                    data-test={`plugin-checks-${group.code}`}
                    className="grid gap-2"
                >
                    <PluginMark name={group.plugin} />
                    {group.failed !== null ? (
                        <p className="text-ink-2 text-small">{group.failed}</p>
                    ) : (
                        <ul className="text-small grid gap-2">
                            {group.checks.map((check, index) => {
                                const goes =
                                    onGoTo !== undefined &&
                                    check.state !== 'ok' &&
                                    (check.field !== null ||
                                        check.ownField !== null);
                                const label = (
                                    <>
                                        <StateIcon state={check.state} />
                                        <span className="font-medium">
                                            {check.label}
                                        </span>
                                        <span className="sr-only">
                                            {' '}
                                            {stateWords(check.state)}
                                        </span>
                                    </>
                                );

                                return (
                                    <li key={`${check.id}-${index}`}>
                                        {goes ? (
                                            <TextButton
                                                onClick={() =>
                                                    onGoTo(group.code, check)
                                                }
                                                className="gap-2 text-left align-top font-normal decoration-transparent underline-offset-2 hover:underline"
                                            >
                                                {label}
                                            </TextButton>
                                        ) : (
                                            <span className="inline-flex items-center gap-2 align-top">
                                                {label}
                                            </span>
                                        )}{' '}
                                        <span className="text-ink-2">
                                            {check.message}
                                        </span>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </section>
            ))}
        </div>
    );
}

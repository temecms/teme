import ExtensionsController from '@/actions/App/Http/Admin/ExtensionsController';
import { flashed } from '@/components/extensions/add-sheet';
import { useAsk } from '@/components/extensions/use-ask';
import {
    actionLabel,
    kindLabel,
    sourceLabel,
    statusLabel,
    statusTone,
} from '@/components/extensions/words';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { Panel } from '@/components/ui/panel';
import { StatusPill } from '@/components/ui/status-pill';
import { TextButton } from '@/components/ui/text-button';
import { __ } from '@/lib/i18n';
import type { ExtensionsData } from '@/types';
import { formatDateTime } from '@/lib/dates';

/** A moment on the site's clock, in the admin's language. */
function when(iso: string): string {
    return formatDateTime(iso);
}

/**
 * The Plugins screen's Install requests tab (I-211): plugin and theme
 * requests together, newest first, each opening its sheet; whether the
 * installer runs; what is held; Roll back; and the saved sources.
 */
export function RequestsList({
    data,
    onOpen,
}: {
    data: ExtensionsData;
    onOpen: (id: string) => void;
}) {
    const { ask, busy, password } = useAsk();
    const anyOpen = data.open !== null;

    const rollBack = () =>
        ask({
            url: ExtensionsController.store.url(),
            data: { action: 'rollback', kind: 'plugin' },
            onFlash: (flash) => {
                const id = flashed(flash, 'extensionRequest');

                if (typeof id === 'string') {
                    onOpen(id);
                }
            },
        });

    return (
        <div className="grid gap-4" data-test="extension-requests">
            <p className="text-small text-ink-2" data-test="installer-state">
                {data.installerPresent
                    ? __('The installer is running.')
                    : data.installerSeenAt === null
                      ? __('No installer has run on this server yet.')
                      : __('The installer was last seen :when.', {
                            when: when(data.installerSeenAt),
                        })}
            </p>

            {data.held !== null && (
                <Notice
                    tone="warn"
                    title={__(
                        'Plugins and themes installed here wait for a rebuild',
                    )}
                    data-test="extension-held"
                >
                    <p className="break-words">
                        {data.held.reason ??
                            __(
                                'The installer rebuilds them for this version of Teme the next time it runs.',
                            )}
                    </p>
                </Notice>
            )}

            <Panel className="p-1">
                {data.requests.length === 0 ? (
                    <p className="text-ink-2 px-3 py-3">
                        {__('Nothing has been asked for yet.')}
                    </p>
                ) : (
                    <ul data-test="extension-request-list">
                        {data.requests.map((request) => (
                            <li
                                key={request.id}
                                className="border-line border-b last:border-b-0"
                            >
                                <div
                                    data-test="extension-request-line"
                                    className="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-1 px-3 py-3"
                                >
                                    <div className="grid min-w-0 flex-1 basis-56 gap-0.5">
                                        <TextButton
                                            onClick={() => onOpen(request.id)}
                                            data-test="extension-request-open"
                                            className="text-body justify-self-start text-start break-all"
                                        >
                                            {request.package ??
                                                actionLabel(request.action)}
                                        </TextButton>
                                        <p className="text-small text-ink-2">
                                            {[
                                                actionLabel(request.action),
                                                kindLabel(request.kind),
                                                sourceLabel(request.sourceKind),
                                                when(request.requestedAt),
                                            ].join(' · ')}
                                        </p>
                                        {request.status === 'failed' &&
                                            request.error !== null && (
                                                <p className="text-small text-danger-ink break-words">
                                                    {request.error}
                                                </p>
                                            )}
                                    </div>
                                    <StatusPill
                                        tone={statusTone(request.status)}
                                    >
                                        {statusLabel(request.status)}
                                    </StatusPill>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            {data.canRollBack && (
                <section
                    aria-labelledby="extension-rollback-title"
                    className="grid gap-2"
                >
                    <h3
                        id="extension-rollback-title"
                        className="text-body font-semibold"
                    >
                        {__('Roll back')}
                    </h3>
                    <p className="text-small text-ink-2">
                        {__(
                            'Puts back the plugins and themes installed here as they were before the last change.',
                        )}
                    </p>
                    <div>
                        <Button
                            type="button"
                            variant="neutral"
                            size="sm"
                            loading={busy}
                            disabled={anyOpen}
                            onClick={rollBack}
                            data-test="extension-rollback"
                        >
                            {__('Ask to roll back')}
                        </Button>
                    </div>
                </section>
            )}

            {data.sources.length > 0 && (
                <section
                    aria-labelledby="extension-sources-title"
                    className="grid gap-2"
                >
                    <h3
                        id="extension-sources-title"
                        className="text-body font-semibold"
                    >
                        {__('Saved sources')}
                    </h3>
                    <ul className="grid gap-2" data-test="extension-sources">
                        {data.sources.map((source) => (
                            <li
                                key={source.id}
                                className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1"
                            >
                                <span className="grid min-w-0 flex-1 basis-56">
                                    <span className="text-body">
                                        {source.label}
                                    </span>
                                    <span className="text-small text-ink-2 break-all">
                                        {[
                                            sourceLabel(source.kind),
                                            source.url,
                                            source.hasKey
                                                ? __('key kept')
                                                : null,
                                        ]
                                            .filter((part) => part !== null)
                                            .join(' · ')}
                                    </span>
                                </span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    loading={busy}
                                    onClick={() =>
                                        ask({
                                            url: ExtensionsController.destroySource.url(
                                                source.id,
                                            ),
                                            method: 'delete',
                                        })
                                    }
                                >
                                    {__('Remove')}
                                    <span className="sr-only">
                                        {' '}
                                        {source.label}
                                    </span>
                                </Button>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
            {password}
        </div>
    );
}

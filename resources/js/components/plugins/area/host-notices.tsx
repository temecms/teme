import { router } from '@inertiajs/react';
import { CircleCheckIcon } from 'lucide-react';
import { useState } from 'react';
import PluginsController from '@/actions/App/Http/Admin/PluginsController';
import { send } from '@/components/editor/editor-http';
import { FreshPasswordDialog } from '@/components/patterns/fresh-password';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { adminLocale, siteZone } from '@/lib/dates';
import { __ } from '@/lib/i18n';

/** An outside host a record named at its last save. */
export type RecordHost = {
    host: string;
    state: 'waiting' | 'agreed';
    by: string | null;
    at: string | null;
    canManage: boolean;
};

/** A day as the admin's language shows it, in the site's zone. */
function day(iso: string): string {
    return new Intl.DateTimeFormat(adminLocale(), {
        timeZone: siteZone(),
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date(iso));
}

/**
 * The notices under a record's form for each host it named: waiting for
 * someone who manages plugins to agree, or agreed. The save already went
 * ahead; agreeing asks for the password when the last one is older than
 * fifteen minutes, and the page is read again after it.
 */
export function RecordHostNotices({
    code,
    plugin,
    hosts,
}: {
    code: string;
    plugin: string;
    hosts: RecordHost[];
}) {
    return (
        <div className="mb-4 grid gap-3">
            {hosts.map((entry) => (
                <HostNotice
                    key={entry.host}
                    code={code}
                    plugin={plugin}
                    entry={entry}
                />
            ))}
        </div>
    );
}

function HostNotice({
    code,
    plugin,
    entry,
}: {
    code: string;
    plugin: string;
    entry: RecordHost;
}) {
    const [asking, setAsking] = useState(false);
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);

    const agree = async () => {
        setBusy(true);
        setProblem(null);

        try {
            const answer = await send(PluginsController.agreeHost({ code }), {
                host: entry.host,
            });

            if (answer.status === 423) {
                setAsking(true);
            } else if (answer.status === 200) {
                router.reload();
            } else {
                setProblem(__('The host couldn’t be agreed this time.'));
            }
        } catch {
            setProblem(__('The host couldn’t be agreed this time.'));
        } finally {
            setBusy(false);
        }
    };

    if (entry.state === 'agreed') {
        return (
            <Notice
                tone="ok"
                title={__('Host agreed.')}
                data-test="record-host-agreed"
            >
                {entry.at !== null
                    ? __(
                          'Agreed on :date; :plugin may reach :host over HTTPS, and no other host.',
                          { date: day(entry.at), plugin, host: entry.host },
                      )
                    : __(
                          ':plugin may reach :host over HTTPS, and no other host.',
                          { plugin, host: entry.host },
                      )}{' '}
                {__(
                    'Anyone who manages plugins can remove it under Plugins › :plugin › Details and access.',
                    { plugin },
                )}
            </Notice>
        );
    }

    if (!entry.canManage) {
        return (
            <Notice
                tone="info"
                title={__(
                    'Waiting for agreement by someone who manages plugins.',
                )}
                data-test="record-host-waiting"
            >
                {__(
                    'Until then Teme keeps the address, and :plugin sends nothing to :host.',
                    { plugin, host: entry.host },
                )}
            </Notice>
        );
    }

    return (
        <Notice
            tone="warn"
            title={__(':plugin can’t reach :host yet.', {
                plugin,
                host: entry.host,
            })}
            data-test="record-host-waiting"
        >
            <p>
                {__(
                    'It’s a new host for this plugin, so someone who manages plugins has to agree to it first. Until then Teme keeps the address, and :plugin sends nothing to it. Agreeing lets :plugin reach that host and no other.',
                    { plugin },
                )}
            </p>
            <div className="mt-2 flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant="neutral"
                    loading={busy}
                    disabled={busy}
                    onClick={() => void agree()}
                    data-test="record-host-agree"
                >
                    <CircleCheckIcon aria-hidden="true" />
                    {__('Agree to this host')}
                </Button>
                <span className="text-small text-ink-2">
                    {__(
                        'Asks for your password if you last gave it more than 15 minutes ago.',
                    )}
                </span>
            </div>
            {problem !== null && <p role="alert">{problem}</p>}
            <FreshPasswordDialog
                open={asking}
                onOpenChange={setAsking}
                title={__('Agree to this host?')}
                text={__(
                    ':plugin will be able to send data to :host, over HTTPS and nowhere else. Agreeing to a host asks for your password; Teme asks again after 15 minutes.',
                    { plugin, host: entry.host },
                )}
                confirmLabel={__('Confirm and agree')}
                onConfirmed={() => {
                    setAsking(false);
                    void agree();
                }}
            />
        </Notice>
    );
}

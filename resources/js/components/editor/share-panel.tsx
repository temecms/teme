import { http, useHttp } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import PreviewLinkController from '@/actions/App/Http/Admin/Contents/PreviewLinkController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { __ } from '@/lib/i18n';
import type { CreatedPreviewLink, PreviewLinkRow } from '@/types';
import { post, reasonOf } from './editor-http';
import { formatDateTime } from '@/lib/dates';

function when(iso: string): string {
    return formatDateTime(iso);
}

/** Who made the link, or what stands in when their account is gone. */
function maker(link: PreviewLinkRow): string {
    return link.createdByName ?? 'a removed account';
}

/** "Opened 3 times, last 14 Aug 10:42", or "Not opened yet". */
function views(link: PreviewLinkRow): string {
    if (link.viewCount === 0 || link.lastViewedAt === null) {
        return __('Not opened yet');
    }

    return link.viewCount === 1
        ? __('Opened once, last :when', { when: when(link.lastViewedAt) })
        : __('Opened :count times, last :when', {
              count: link.viewCount,
              when: when(link.lastViewedAt),
          });
}

/** The pane's own sentences, looked up when drawn, as strings so a formatter never splits them. */
function shareWords() {
    return {
        intro: __(
            'A preview link shows this version to anyone who has it, without an account, for two days. It opens nothing else. Making one saves your changes first.',
        ),
        loadFailed: __('The links could not be loaded.'),
        makeFailed: __('The link could not be made. Try again.'),
        revokeFailed: __('The link could not be revoked. Try again.'),
        notSaved: __(
            'Your work couldn’t be saved, so no link was made. The save state says why.',
        ),
    };
}

/**
 * Share, a pane of the rail (I-1316): a link that shows this version to someone with no account, for two days. Making one saves the person's changes first, so the link shows what's on the screen. The link's address is shown once, when it is made; every link is listed with its expiry, who made it and its views, and Revoke on each that still works.
 */
export function SharePane({
    contentId,
    saveFirst,
}: {
    contentId: string;
    /** The editor's explicit save: the working revision's ID, or null when the save was refused or stale. */
    saveFirst: () => Promise<string | null>;
}) {
    const { submit } = useHttp();
    const [links, setLinks] = useState<PreviewLinkRow[]>([]);
    const [made, setMade] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const SHARE_WORDS = shareWords();

    const load = useCallback(async () => {
        try {
            setLinks(
                (await submit(
                    PreviewLinkController.index(contentId),
                )) as PreviewLinkRow[],
            );
        } catch {
            setProblem(SHARE_WORDS.loadFailed);
        }
    }, [contentId, submit]);

    useEffect(() => {
        void load();
    }, [load]);

    const make = async () => {
        setBusy(true);
        setProblem(null);

        try {
            // What's on the screen is what the link shows.
            if ((await saveFirst()) === null) {
                setProblem(SHARE_WORDS.notSaved);

                return;
            }

            const answer = await post(
                PreviewLinkController.store(contentId).url,
            );

            if (answer.status >= 400) {
                setProblem(reasonOf(answer.body));

                return;
            }

            setMade((answer.body as CreatedPreviewLink).url);
            setCopied(false);
            await load();
        } catch {
            setProblem(SHARE_WORDS.makeFailed);
        } finally {
            setBusy(false);
        }
    };

    const revoke = async (id: string) => {
        setProblem(null);

        try {
            await http.getClient().request({
                method: 'delete',
                url: PreviewLinkController.destroy({
                    content: contentId,
                    link: id,
                }).url,
                headers: { Accept: 'application/json' },
            });
            await load();
        } catch {
            setProblem(SHARE_WORDS.revokeFailed);
        }
    };

    return (
        <div className="text-small grid gap-3 p-4">
            <p>{SHARE_WORDS.intro}</p>
            <Button
                type="button"
                size="sm"
                data-test="share-make"
                disabled={busy}
                onClick={() => void make()}
                className="justify-self-start"
            >
                {busy ? __('Making…') : __('Make a preview link')}
            </Button>
            {made && (
                <div className="grid gap-2">
                    <Label htmlFor="share-url">
                        {__('The new preview link')}
                    </Label>
                    <div className="flex gap-2">
                        <Input
                            id="share-url"
                            readOnly
                            value={made}
                            onFocus={(event) => event.target.select()}
                        />
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                void navigator.clipboard
                                    .writeText(made)
                                    .then(() => setCopied(true))
                            }
                        >
                            {copied ? __('Copied') : __('Copy')}
                        </Button>
                    </div>
                    <p className="text-ink-2 text-caption">
                        {__('Copy it now: it will not be shown again.')}
                    </p>
                </div>
            )}
            {problem && (
                <p role="alert" className="text-danger-ink text-caption">
                    {problem}
                </p>
            )}
            {links.length > 0 && (
                <ul
                    aria-label="Preview links"
                    data-test="share-links"
                    className="border-line grid gap-2 border-t pt-3"
                >
                    {links.map((link) => (
                        <li
                            key={link.id}
                            className="flex items-start justify-between gap-2"
                        >
                            <span className="grid gap-0.5">
                                <span>
                                    {link.live
                                        ? __('Works until :when', {
                                              when: when(link.expiresAt),
                                          })
                                        : link.revokedAt
                                          ? __('Revoked :when', {
                                                when: when(link.revokedAt),
                                            })
                                          : __('Expired :when', {
                                                when: when(link.expiresAt),
                                            })}
                                </span>
                                <span className="text-ink-2 text-caption">
                                    {__('Made by :name, :when · :views', {
                                        name: maker(link),
                                        when: when(link.createdAt),
                                        views: views(link),
                                    })}
                                </span>
                            </span>
                            {link.live && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="text-danger-ink"
                                    onClick={() => void revoke(link.id)}
                                >
                                    {__('Revoke')}
                                    <span className="sr-only">
                                        {' '}
                                        {__('the link made :when', {
                                            when: when(link.createdAt),
                                        })}
                                    </span>
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

import { http, useHttp } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import PreviewLinkController from '@/actions/App/Http/Admin/Contents/PreviewLinkController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { CreatedPreviewLink, PreviewLinkRow } from '@/types';
import { post, reasonOf } from './editor-http';

function when(iso: string): string {
    return new Date(iso).toLocaleString([], {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

/** "Opened 3 times, last 14 Aug 10:42", or "Not opened yet". */
function views(link: PreviewLinkRow): string {
    if (link.viewCount === 0 || link.lastViewedAt === null) {
        return 'Not opened yet';
    }

    const times = link.viewCount === 1 ? 'once' : `${link.viewCount} times`;

    return `Opened ${times}, last ${when(link.lastViewedAt)}`;
}

/** The pane's own sentences, as strings, so a formatter never splits them. */
const SHARE_WORDS = {
    intro: 'A preview link shows this version to anyone who has it, without an account, for two days. It opens nothing else. Making one saves your changes first.',
    loadFailed: 'The links could not be loaded.',
    makeFailed: 'The link could not be made. Try again.',
    revokeFailed: 'The link could not be revoked. Try again.',
    notSaved:
        'Your work couldn’t be saved, so no link was made. The save state says why.',
};

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
        <div className="grid gap-3 p-4 text-[13px]">
            <p>{SHARE_WORDS.intro}</p>
            <Button
                type="button"
                size="sm"
                data-test="share-make"
                disabled={busy}
                onClick={() => void make()}
                className="justify-self-start"
            >
                {busy ? 'Making…' : 'Make a preview link'}
            </Button>
            {made && (
                <div className="grid gap-1.5">
                    <Label htmlFor="share-url">The new preview link</Label>
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
                            className="h-[34px]"
                            onClick={() =>
                                void navigator.clipboard
                                    .writeText(made)
                                    .then(() => setCopied(true))
                            }
                        >
                            {copied ? 'Copied' : 'Copy'}
                        </Button>
                    </div>
                    <p className="text-ink-2 text-xs">
                        Copy it now: it will not be shown again.
                    </p>
                </div>
            )}
            {problem && (
                <p role="alert" className="text-danger-ink text-xs">
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
                                        ? `Works until ${when(link.expiresAt)}`
                                        : link.revokedAt
                                          ? `Revoked ${when(link.revokedAt)}`
                                          : `Expired ${when(link.expiresAt)}`}
                                </span>
                                <span className="text-ink-2 text-xs">
                                    Made by{' '}
                                    {link.createdByName ?? 'a removed account'},{' '}
                                    {when(link.createdAt)} · {views(link)}
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
                                    Revoke
                                    <span className="sr-only">
                                        {' '}
                                        the link made {when(link.createdAt)}
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

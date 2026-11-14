import { Link } from '@inertiajs/react';
import { CircleAlert, CircleCheck, CircleX } from 'lucide-react';
import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { formatInZone } from '@/lib/zoned-time';
import { edit } from '@/routes/content';
import type { CommentDetails } from '@/types';
import { takesReplyAndQuickEdit } from './use-comment-keys';
import { shownTitle } from '@/lib/titles';

/** What a check's state says to someone who can't see its icon. */
const STATE_WORDS = {
    ok: 'passed',
    warn: 'a reason to hold it',
    bad: 'a reason to send it to spam',
} as const;

const STATE_ICONS = {
    ok: CircleCheck,
    warn: CircleAlert,
    bad: CircleX,
} as const;

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid grid-cols-[7rem_1fr] gap-3 text-[13px]">
            <dt className="text-ink-2">{label}</dt>
            <dd className="min-w-0 break-words">{children}</dd>
        </div>
    );
}

/**
 * One comment's details and the moderator's judgement, in a drawer the list
 * or an address opens (1.8c's D13, D16; v9's commentDetailsBody).
 */
export function CommentDetailsDrawer({
    details,
    seesAddresses,
    siteTimezone,
    onClose,
    onMove,
    onDelete,
    onQuickEdit,
}: {
    details: CommentDetails | null;
    seesAddresses: boolean;
    siteTimezone: string;
    onClose: () => void;
    /** A move to a status a moderator may set, or `restore`. */
    onMove: (to: string) => void;
    onDelete: () => void;
    /** Opens Quick edit over the list, the drawer closed first (D16). */
    onQuickEdit: () => void;
}) {
    const judgement = details?.judgement;
    const parked = details?.status === 'spam' || details?.status === 'trash';
    const none = <span className="text-ink-2">None</span>;
    // A reply written in the admin keeps no address or browser, and says why (C6).
    const noneHere = details?.writtenInAdmin ? (
        <span className="text-ink-2">None: written in the admin</span>
    ) : (
        none
    );
    // When the address and browser go (1.8d's D14). A ping, or a reply written
    // in the admin, never had either, so it keeps its own words, not "Erased".
    const days = (n: number) => (n === 1 ? '1 day' : `${n} days`);
    const erasedNow =
        details?.kind === 'comment' &&
        !details.ip &&
        !details.userAgent &&
        !details.writtenInAdmin &&
        Date.now() >= Date.parse(details.erasesAt);
    const erasedOn =
        details === null || details === undefined
            ? ''
            : new Intl.DateTimeFormat(undefined, {
                  dateStyle: 'medium',
                  timeZone: siteTimezone,
              }).format(new Date(details.erasesAt));
    const erased = <span className="text-ink-2">Erased</span>;
    const under = (words: string) => (
        <span className="text-ink-2 block text-xs">{words}</span>
    );
    const imported =
        details?.legacyId != null
            ? `WordPress ${details.kind} ${details.legacyId}. Old links to #comment-${details.legacyId} still land on it.`
            : null;

    return (
        <Sheet
            open={details !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <SheetContent
                // The drawer's own title says what it is.
                aria-describedby={undefined}
                className="w-full overflow-y-auto sm:max-w-[480px]"
            >
                {details && judgement && (
                    <>
                        <SheetHeader>
                            <SheetTitle>
                                {`Comment by ${details.authorName}`}
                            </SheetTitle>
                        </SheetHeader>
                        <div className="space-y-5 px-4 pb-4">
                            <dl className="space-y-2">
                                <Row label="Status">
                                    <Badge variant="secondary">
                                        {details.statusLabel}
                                    </Badge>
                                </Row>
                                <Row label="Name">
                                    {details.authorName}{' '}
                                    {details.signedIn && (
                                        <Badge variant="secondary">
                                            Signed in
                                        </Badge>
                                    )}
                                </Row>
                                <Row label="Email">
                                    {details.authorEmail ?? none}
                                </Row>
                                <Row label="Website">
                                    {details.authorUrl ?? none}
                                </Row>
                                <Row label="On">
                                    {details.item.editable ? (
                                        <Link
                                            href={edit(details.item.id)}
                                            className="underline underline-offset-2"
                                        >
                                            {shownTitle(details.item.title)}
                                        </Link>
                                    ) : (
                                        shownTitle(details.item.title)
                                    )}
                                </Row>
                                {details.parent && (
                                    <Row label="In reply to">
                                        {details.parent.removed ||
                                        details.parent.authorName === null
                                            ? 'A removed comment'
                                            : details.parent.authorName}
                                    </Row>
                                )}
                                <Row label="Submitted">
                                    {formatInZone(
                                        details.createdAt,
                                        siteTimezone,
                                    )}
                                </Row>
                                {seesAddresses && (
                                    <>
                                        <Row label="IP address">
                                            {details.ip ? (
                                                <>
                                                    {details.ip}
                                                    {under(
                                                        `Erased on ${erasedOn}, ${days(details.keptDays)} after it arrived`,
                                                    )}
                                                </>
                                            ) : erasedNow ? (
                                                <>
                                                    {erased}
                                                    {under(
                                                        `Kept ${days(details.keptDays)}, then erased`,
                                                    )}
                                                </>
                                            ) : (
                                                noneHere
                                            )}
                                        </Row>
                                        <Row label="Browser">
                                            {details.userAgent ? (
                                                <>
                                                    {details.userAgent}
                                                    {under(
                                                        'Erased with the IP address',
                                                    )}
                                                </>
                                            ) : erasedNow ? (
                                                erased
                                            ) : (
                                                noneHere
                                            )}
                                        </Row>
                                    </>
                                )}
                                {imported && (
                                    <Row label="Imported">{imported}</Row>
                                )}
                            </dl>

                            <section aria-labelledby="comment-text-h">
                                <h3
                                    id="comment-text-h"
                                    className="mb-1 text-[13px] font-semibold"
                                >
                                    Comment
                                </h3>
                                <p className="text-[13px] whitespace-pre-line">
                                    {details.text}
                                </p>
                            </section>

                            <section aria-labelledby="comment-moderation-h">
                                <h3
                                    id="comment-moderation-h"
                                    className="mb-1 text-[13px] font-semibold"
                                >
                                    Moderation
                                </h3>
                                {judgement.byline && (
                                    <p className="text-ink-2 text-[12px]">
                                        {judgement.byline}
                                    </p>
                                )}
                                <p className="mt-1 text-[13px] font-semibold">
                                    {judgement.verdict}
                                </p>
                                {judgement.checks.length > 0 && (
                                    <ul className="mt-2 space-y-1.5">
                                        {judgement.checks.map((check) => {
                                            const Icon =
                                                STATE_ICONS[check.state];

                                            return (
                                                <li
                                                    key={check.label}
                                                    className="flex items-start gap-2 text-[13px]"
                                                >
                                                    <Icon
                                                        aria-hidden
                                                        className="mt-0.5 size-4 shrink-0"
                                                    />
                                                    <span>
                                                        <strong>
                                                            {check.label}
                                                        </strong>
                                                        <span className="sr-only">
                                                            {` ${STATE_WORDS[check.state]}`}
                                                        </span>
                                                        {`: ${check.detail}`}
                                                    </span>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                )}
                                {judgement.hint && (
                                    <p className="text-ink-2 mt-2 text-[12px]">
                                        {judgement.hint}
                                    </p>
                                )}
                            </section>
                        </div>
                        <SheetFooter className="flex-row flex-wrap justify-end gap-2">
                            {parked ? (
                                <>
                                    <Button
                                        variant="destructive"
                                        onClick={onDelete}
                                    >
                                        Delete permanently
                                    </Button>
                                    <Button onClick={() => onMove('restore')}>
                                        {details.status === 'spam'
                                            ? 'Mark as not spam'
                                            : 'Restore'}
                                    </Button>
                                </>
                            ) : (
                                <>
                                    <Button
                                        variant="outline"
                                        onClick={() => onMove('spam')}
                                    >
                                        Mark as spam
                                    </Button>
                                    <Button
                                        variant="destructive"
                                        onClick={() => onMove('trash')}
                                    >
                                        Move to trash
                                    </Button>
                                    {takesReplyAndQuickEdit(details) && (
                                        <Button
                                            variant="outline"
                                            onClick={onQuickEdit}
                                        >
                                            Quick edit
                                        </Button>
                                    )}
                                    <Button
                                        onClick={() =>
                                            onMove(
                                                details.status === 'pending'
                                                    ? 'approved'
                                                    : 'pending',
                                            )
                                        }
                                    >
                                        {details.status === 'pending'
                                            ? 'Approve'
                                            : 'Unapprove'}
                                    </Button>
                                </>
                            )}
                        </SheetFooter>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}

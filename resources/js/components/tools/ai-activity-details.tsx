import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { costWords, secondsWords, whenWords } from '@/lib/ai-cost';
import { edit } from '@/routes/content';
import type { AiActivityRow } from '@/types';

/** What each way a request ends says in the drawer, when its own words don't. */
const OUTCOME_WORDS: Record<string, string> = {
    suggested: 'AI made a suggestion. It hasn’t been used or discarded.',
    applied: 'The suggestion was used.',
    discarded: 'The suggestion was discarded.',
    stale: 'The text changed after the suggestion was made, so it wasn’t used.',
};

/**
 * How a request ended, as a badge. The words carry the meaning; the tint
 * only helps.
 */
export function OutcomeBadge({
    outcome,
}: {
    outcome: AiActivityRow['outcome'];
}) {
    const variant =
        outcome.key === 'applied'
            ? 'success'
            : outcome.key === 'failed' || outcome.key === 'refused'
              ? 'destructive'
              : 'secondary';

    return <Badge variant={variant}>{outcome.label}</Badge>;
}

/**
 * What a request was about: the item's title, a link while it can be opened,
 * and what kind it is beneath; or that it has gone.
 */
export function AiSubject({ subject }: { subject: AiActivityRow['subject'] }) {
    if (subject.state === 'gone') {
        return (
            <span className="text-muted-foreground">
                {subject.kind === 'media'
                    ? 'A deleted image'
                    : 'A deleted item'}
            </span>
        );
    }

    const title =
        subject.title === null || subject.title === ''
            ? 'Untitled'
            : subject.title;
    const openable = subject.kind === 'content' && subject.state === 'present';

    return (
        <>
            {openable ? (
                <Link
                    href={edit(subject.id)}
                    className="underline-offset-2 hover:underline"
                >
                    {title}
                </Link>
            ) : (
                title
            )}
            <span className="text-muted-foreground block text-[12px]">
                {subject.state === 'trashed'
                    ? `${subject.kindLabel}, in the trash`
                    : subject.kindLabel}
            </span>
        </>
    );
}

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid grid-cols-[8rem_1fr] gap-3 text-[13px]">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="min-w-0 break-words">{children}</dd>
        </div>
    );
}

/**
 * One request in full, in a drawer the list or an address opens: how it
 * ended and why, what it was about, which model answered, the four token
 * counts, the time and the cost.
 */
export function AiActivityDetails({
    details,
    siteTimezone,
    onClose,
}: {
    details: AiActivityRow | null;
    siteTimezone: string;
    onClose: () => void;
}) {
    const cost =
        details === null
            ? null
            : costWords(details.costMicrounits, details.tokens);
    const failed =
        details !== null &&
        (details.outcome.key === 'failed' || details.outcome.key === 'refused');

    return (
        <Sheet
            open={details !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <SheetContent
                // The drawer's own title says what it is.
                aria-describedby={undefined}
                data-test="ai-activity-details"
                className="w-full overflow-y-auto sm:max-w-[480px]"
            >
                {details && cost && (
                    <>
                        <SheetHeader>
                            <SheetTitle>{`${details.task.label} request`}</SheetTitle>
                        </SheetHeader>
                        <div className="px-4 pb-4">
                            <dl className="space-y-2">
                                <Row label="Result">
                                    <OutcomeBadge outcome={details.outcome} />
                                    <span className="mt-1 block">
                                        {failed
                                            ? `${details.why ?? ''} Nothing was changed.`.trim()
                                            : OUTCOME_WORDS[
                                                  details.outcome.key
                                              ]}
                                    </span>
                                </Row>
                                <Row label="When">
                                    {whenWords(details.at, siteTimezone)}
                                </Row>
                                <Row label="Person">
                                    {details.person.name ?? (
                                        <span className="text-muted-foreground">
                                            A removed person
                                        </span>
                                    )}
                                </Row>
                                <Row label="Task">
                                    {details.variant === null
                                        ? details.task.label
                                        : `${details.task.label} · ${details.variant.label}`}
                                </Row>
                                <Row label="On">
                                    <AiSubject subject={details.subject} />
                                </Row>
                                <Row label="Model">
                                    {details.model === null ? (
                                        <span className="text-muted-foreground">
                                            None
                                        </span>
                                    ) : (
                                        <>
                                            <span className="font-mono text-[12px]">
                                                {details.model}
                                            </span>
                                            {details.provider !== null && (
                                                <span className="text-muted-foreground block text-[12px]">
                                                    {details.provider}
                                                </span>
                                            )}
                                        </>
                                    )}
                                </Row>
                                <Row label="Prompt">
                                    {`${details.prompt.key}, version ${details.prompt.version}`}
                                </Row>
                                <Row label="Tokens sent">
                                    {details.tokens.input.toLocaleString()}
                                </Row>
                                <Row label="Tokens received">
                                    {details.tokens.output.toLocaleString()}
                                </Row>
                                <Row label="Read from the cache">
                                    {details.tokens.cacheRead.toLocaleString()}
                                </Row>
                                <Row label="Written to the cache">
                                    {details.tokens.cacheWrite.toLocaleString()}
                                </Row>
                                <Row label="Time taken">
                                    {secondsWords(details.latencyMs)}
                                </Row>
                                <Row label="Cost">
                                    <span
                                        className={
                                            cost.known
                                                ? undefined
                                                : 'text-muted-foreground'
                                        }
                                    >
                                        {cost.text}
                                    </span>
                                    {details.costMicrounits === null && (
                                        <span className="text-muted-foreground block text-[12px]">
                                            No price is set for this model, so
                                            this request isn’t counted in the
                                            month’s spend.
                                        </span>
                                    )}
                                </Row>
                                {details.altText && (
                                    <>
                                        <Row label="Alt text before">
                                            {details.altText.before ?? (
                                                <span className="text-muted-foreground">
                                                    None
                                                </span>
                                            )}
                                            <span className="text-muted-foreground block text-[12px]">
                                                {details.altText.before === null
                                                    ? 'The image had none. To put that back, clear its alt text in Media.'
                                                    : 'To put it back, copy it into the image’s alt text in Media.'}
                                            </span>
                                        </Row>
                                        <Row label="Alt text after">
                                            {details.altText.after}
                                        </Row>
                                    </>
                                )}
                            </dl>
                        </div>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}

import { Link } from '@inertiajs/react';
import type { CSSProperties, ReactNode } from 'react';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { StatusPill } from '@/components/ui/status-pill';
import { costWords, secondsWords, whenWords } from '@/lib/ai-cost';
import { __ } from '@/lib/i18n';
import { edit } from '@/routes/content';
import type { AiActivityRow } from '@/types';

/** What each way a request ends says in the drawer, when its own words don't, looked up as it is drawn. */
function outcomeWords(key: string): string | undefined {
    const words: Record<string, string> = {
        suggested: __(
            'AI made a suggestion. It hasn’t been used or discarded.',
        ),
        applied: __('The suggestion was used.'),
        discarded: __('The suggestion was discarded.'),
        stale: __(
            'The text changed after the suggestion was made, so it wasn’t used.',
        ),
    };

    return words[key];
}

/** The drawer's two columns from the small breakpoint up. */
const FACTS = { '--facts-cols': '8rem 1fr' } as CSSProperties;

/**
 * How a request ended, as a status pill. The words carry the meaning; the
 * tone only helps.
 */
export function OutcomeBadge({
    outcome,
}: {
    outcome: AiActivityRow['outcome'];
}) {
    const tone =
        outcome.key === 'applied'
            ? 'ok'
            : outcome.key === 'failed' || outcome.key === 'refused'
              ? 'danger'
              : outcome.key === 'suggested'
                ? 'info'
                : 'neutral';

    return <StatusPill tone={tone}>{outcome.label}</StatusPill>;
}

/**
 * What a request was about: the item's title, a link while it can be opened,
 * and what kind it is beneath; or that it has gone.
 */
export function AiSubject({ subject }: { subject: AiActivityRow['subject'] }) {
    if (subject.state === 'gone') {
        return (
            <span className="text-ink-2">
                {subject.kind === 'media'
                    ? __('A deleted image')
                    : __('A deleted item')}
            </span>
        );
    }

    const title =
        subject.title === null || subject.title === ''
            ? __('Untitled')
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
            <span className="text-ink-2 text-caption block">
                {subject.state === 'trashed'
                    ? __(':kind, in the trash', { kind: subject.kindLabel })
                    : subject.kindLabel}
            </span>
        </>
    );
}

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div
            className="text-body grid gap-x-3 gap-y-1 sm:grid-cols-(--facts-cols)"
            style={FACTS}
        >
            <dt className="text-small text-ink-2">{label}</dt>
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
            >
                {details && cost && (
                    <>
                        <SheetHeader>
                            <SheetTitle>
                                {__(':task request', {
                                    task: details.task.label,
                                })}
                            </SheetTitle>
                        </SheetHeader>
                        <ScrollArea className="px-6 pb-4">
                            <dl className="grid gap-2">
                                <Row label={__('Result')}>
                                    <OutcomeBadge outcome={details.outcome} />
                                    <span className="mt-1 block">
                                        {failed
                                            ? __(':why Nothing was changed.', {
                                                  why: details.why ?? '',
                                              }).trim()
                                            : outcomeWords(details.outcome.key)}
                                    </span>
                                </Row>
                                <Row label={__('When')}>
                                    {whenWords(details.at, siteTimezone)}
                                </Row>
                                <Row label={__('Person')}>
                                    {details.person.name ?? (
                                        <span className="text-ink-2">
                                            {__('A removed person')}
                                        </span>
                                    )}
                                </Row>
                                <Row label={__('Task')}>
                                    {details.variant === null
                                        ? details.task.label
                                        : `${details.task.label} · ${details.variant.label}`}
                                </Row>
                                <Row label={__('On')}>
                                    <AiSubject subject={details.subject} />
                                </Row>
                                <Row label={__('Model')}>
                                    {details.model === null ? (
                                        <span className="text-ink-2">
                                            {__('None')}
                                        </span>
                                    ) : (
                                        <>
                                            <span className="text-small font-mono">
                                                {details.model}
                                            </span>
                                            {details.provider !== null && (
                                                <span className="text-ink-2 text-caption block">
                                                    {details.provider}
                                                </span>
                                            )}
                                        </>
                                    )}
                                </Row>
                                <Row label={__('Prompt')}>
                                    {__(':key, version :version', {
                                        key: details.prompt.key,
                                        version: details.prompt.version,
                                    })}
                                </Row>
                                <Row label={__('Tokens in')}>
                                    {details.tokens.input.toLocaleString()}
                                </Row>
                                <Row label={__('Tokens out')}>
                                    {details.tokens.output.toLocaleString()}
                                </Row>
                                <Row label={__('Read from the cache')}>
                                    {details.tokens.cacheRead.toLocaleString()}
                                </Row>
                                <Row label={__('Written to the cache')}>
                                    {details.tokens.cacheWrite.toLocaleString()}
                                </Row>
                                <Row label={__('Time taken')}>
                                    {secondsWords(details.latencyMs)}
                                </Row>
                                <Row label={__('Cost')}>
                                    <span
                                        className={
                                            cost.known
                                                ? undefined
                                                : 'text-ink-2'
                                        }
                                    >
                                        {cost.text}
                                    </span>
                                    {details.costMicrounits === null && (
                                        <span className="text-ink-2 text-caption block">
                                            {__(
                                                'No price is set for this model, so this request isn’t counted in the month’s spend.',
                                            )}
                                        </span>
                                    )}
                                </Row>
                                {details.altText && (
                                    <>
                                        <Row label={__('Alt text before')}>
                                            {details.altText.before ?? (
                                                <span className="text-ink-2">
                                                    {__('None')}
                                                </span>
                                            )}
                                            <span className="text-ink-2 text-caption block">
                                                {details.altText.before === null
                                                    ? __(
                                                          'The image had none. To put that back, clear its alt text in Media.',
                                                      )
                                                    : __(
                                                          'To put it back, copy it into the image’s alt text in Media.',
                                                      )}
                                            </span>
                                        </Row>
                                        <Row label={__('Alt text after')}>
                                            {details.altText.after}
                                        </Row>
                                    </>
                                )}
                            </dl>
                            <p className="text-ink-2 text-small mt-4">
                                {__(
                                    'Teme keeps these figures, never the text sent or the answer.',
                                )}
                            </p>
                        </ScrollArea>
                        <SheetFooter className="mt-auto flex-row border-t">
                            <SheetClose asChild>
                                <Button type="button" variant="outline">
                                    {__('Close')}
                                </Button>
                            </SheetClose>
                        </SheetFooter>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}

import { __ } from '@/lib/i18n';
import { Sparkles } from 'lucide-react';
import { useCallback, useEffect, useId, useRef, useState } from 'react';
import AiRequestController from '@/actions/App/Http/Admin/Ai/AiRequestController';
import { Proposal } from '@/components/ai/proposal';
import {
    ASSISTANT_WORDS,
    limitSentence,
} from '@/components/editor/assistant/assistant-context';
import { send } from '@/components/editor/editor-http';
import type { Answer } from '@/components/editor/editor-http';
import { providerList } from '@/components/editor/rail/assistant-panel';
import { showToast } from '@/components/flash-toast';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { show as showMedia } from '@/routes/media';
import type { AltTextOffer, MediaDetailsData } from '@/types';
import { ALT_TEXT_EVENT, useAltTextTarget } from './alt-text-target';

/** One request for an image's alt text, from asking to a proposal or a failure. */
type AltRequest = {
    id: string | null;
    before: string;
    state: 'asking' | 'working' | 'ready' | 'using' | 'failed';
    suggestion: string;
    sentTo: string[];
    message: string | null;
    slow: boolean;
};

/** The card's own words, beside the assistant's, looked up while drawing. */
function altTextWords() {
    return {
        suggest: __('Suggest alt text'),
        heading: __('Suggested alt text'),
        ready: __('A suggestion is ready.'),
        used: __('Suggested alt text saved.'),
    };
}

/** After this long working the card adds the queue line; after the hand-off's 15 minutes the request has expired. */
const SLOW_AFTER_MS = 75_000;
const EXPIRES_AFTER_MS = 15 * 60_000;

/** What the server's refusal of an ask means for the person. */
function refusal(answer: Answer, hourlyLimit: number): string {
    if (answer.status === 429) {
        return limitSentence(hourlyLimit);
    }

    if (answer.status === 403) {
        return ASSISTANT_WORDS.forbidden;
    }

    // The server's own words for people: AI unavailable, or the budget spent.
    if (answer.status === 409 && typeof answer.body.message === 'string') {
        return answer.body.message;
    }

    return ASSISTANT_WORDS.unexpected;
}

/**
 * Suggest alt text, under an image's alt text field, in the
 * library's drawer and the editor's media window alike. It asks AI about
 * this image, collects the answer by polling, shows it beside what the field
 * holds, and Use has the server save it as the image's alt text at once
 * (I-317). Closing the details, or opening another image, puts a request
 * aside. The palette asks through the window event teme:alt-text.
 */
export function AltTextSuggestion({
    item,
    offer,
    currentAlt,
    onUsed,
}: {
    item: MediaDetailsData;
    offer: AltTextOffer;
    /** What the alt text field holds now, saved or not. */
    currentAlt: () => string;
    onUsed: (details: MediaDetailsData) => void;
}) {
    const token = useAltTextTarget();
    const heading = useId();
    const [request, setRequest] = useState<AltRequest | null>(null);
    const current = useRef<AltRequest | null>(null);
    const timer = useRef<number | null>(null);
    const suggestButton = useRef<HTMLButtonElement>(null);

    const show = useCallback((next: AltRequest | null) => {
        current.current = next;
        setRequest(next);
    }, []);

    const stopCollecting = useCallback(() => {
        if (timer.current !== null) {
            window.clearTimeout(timer.current);
            timer.current = null;
        }
    }, []);

    // Every second for ten seconds, then every two, until the answer or a failure.
    const collect = useCallback(
        (id: string, startedAt: number, polls: number) => {
            timer.current = window.setTimeout(
                async () => {
                    timer.current = null;
                    let answer: Answer;

                    try {
                        answer = await send(AiRequestController.show(id));
                    } catch {
                        // Offline for a moment: ask again.
                        collect(id, startedAt, polls + 1);

                        return;
                    }

                    const was = current.current;

                    // Put aside, or replaced by another request, meanwhile.
                    if (was === null || was.id !== id) {
                        return;
                    }

                    const sentTo = Array.isArray(answer.body.sentTo)
                        ? (answer.body.sentTo as string[])
                        : was.sentTo;
                    const waited = Date.now() - startedAt;

                    if (answer.status === 404 || waited > EXPIRES_AFTER_MS) {
                        show({
                            ...was,
                            state: 'failed',
                            message: ASSISTANT_WORDS.expired,
                        });
                    } else if (answer.body.state === 'ready') {
                        const values = answer.body.values as Record<
                            string,
                            string
                        >;
                        show({
                            ...was,
                            state: 'ready',
                            suggestion: values.text ?? '',
                            sentTo,
                            slow: false,
                        });
                    } else if (answer.body.state === 'failed') {
                        show({
                            ...was,
                            state: 'failed',
                            message:
                                typeof answer.body.message === 'string'
                                    ? answer.body.message
                                    : ASSISTANT_WORDS.unexpected,
                            sentTo,
                            slow: false,
                        });
                    } else {
                        show({ ...was, sentTo, slow: waited > SLOW_AFTER_MS });
                        collect(id, startedAt, polls + 1);
                    }
                },
                polls < 10 ? 1000 : 2000,
            );
        },
        [show],
    );

    // Cancel, Discard and Dismiss alike: the server cancels the request or puts the suggestion aside.
    const putAside = useCallback(async () => {
        const was = current.current;
        stopCollecting();
        show(null);

        if (was !== null && was.id !== null) {
            try {
                await send(AiRequestController.destroy(was.id));
            } catch {
                // Offline: the server forgets it within 15 minutes anyway.
            }
        }
    }, [show, stopCollecting]);

    const ask = useCallback(async () => {
        if (current.current !== null) {
            await putAside();
        }

        const asked: AltRequest = {
            id: null,
            before: currentAlt(),
            state: 'asking',
            suggestion: '',
            sentTo: [],
            message: null,
            slow: false,
        };
        show(asked);

        let answer: Answer;

        try {
            answer = await send(AiRequestController.store(), {
                task: 'alt-text',
                subject_type: 'media',
                subject_id: item.id,
                revision_id: null,
                target: 'alt',
                options: {},
            });
        } catch {
            if (current.current === asked) {
                show({
                    ...asked,
                    state: 'failed',
                    message: ASSISTANT_WORDS.offline,
                });
            }

            return;
        }

        // Put aside, or closed, while it was sent: cancel it quietly.
        if (current.current !== asked) {
            if (answer.status === 202) {
                void send(
                    AiRequestController.destroy(String(answer.body.id)),
                ).catch(() => undefined);
            }

            return;
        }

        if (answer.status !== 202) {
            show({
                ...asked,
                state: 'failed',
                message: refusal(answer, offer.hourlyLimit),
            });

            return;
        }

        const id = String(answer.body.id);
        show({ ...asked, id, state: 'working' });
        collect(id, Date.now(), 0);
    }, [collect, currentAlt, item.id, offer.hourlyLimit, putAside, show]);

    // Not named "use": the hooks lint reads any use… call as a hook.
    const applySuggestion = useCallback(async () => {
        const was = current.current;

        if (was === null || was.id === null || was.state !== 'ready') {
            return;
        }

        show({ ...was, state: 'using', message: null });

        try {
            const answer = await send(AiRequestController.apply(was.id));

            if (answer.status === 200) {
                show(null);
                // The details again, so the field shows what was saved and later saves build on it;
                // if they can't be read, the saved text stands in for them.
                let details: MediaDetailsData = {
                    ...item,
                    alt: was.suggestion,
                };

                try {
                    const fresh = await send(showMedia({ media: item.id }));

                    if (fresh.status === 200) {
                        details = fresh.body as unknown as MediaDetailsData;
                    }
                } catch {
                    // Offline after the save: the stand-in is right.
                }

                onUsed(details);
                showToast({ type: 'success', message: altTextWords().used });
                window.requestAnimationFrame(() =>
                    document
                        .getElementById(`attachment-${item.id}-alt`)
                        ?.focus(),
                );

                return;
            }

            show({
                ...was,
                state: 'failed',
                message:
                    answer.status === 409 &&
                    typeof answer.body.message === 'string'
                        ? answer.body.message
                        : answer.status === 403
                          ? ASSISTANT_WORDS.forbidden
                          : ASSISTANT_WORDS.unexpected,
            });
        } catch {
            show({ ...was, message: ASSISTANT_WORDS.offline });
        }
    }, [item, onUsed, show]);

    // Closed, or another image opened: a request still waiting, or a suggestion not used, is put aside.
    const abandon = useCallback(() => {
        stopCollecting();
        const was = current.current;
        current.current = null;

        if (was !== null && was.id !== null && was.state !== 'using') {
            void send(AiRequestController.destroy(was.id)).catch(
                () => undefined,
            );
        }
    }, [stopCollecting]);

    useEffect(() => abandon, [abandon]);

    // The palette, outside the details, asks through an event naming these details (I-1318).
    useEffect(() => {
        const asked = (event: Event) => {
            const detail = (
                event as CustomEvent<{ target?: string } | undefined>
            ).detail;

            if (detail?.target === token) {
                void ask();
            }
        };

        window.addEventListener(ALT_TEXT_EVENT, asked);

        return () => window.removeEventListener(ALT_TEXT_EVENT, asked);
    }, [ask, token]);

    // From the card's own buttons: focus goes back to Suggest alt text, which stays.
    const putAsideFromCard = () => {
        suggestButton.current?.focus();
        void putAside();
    };

    const working = request?.state === 'asking' || request?.state === 'working';
    const proposing = request?.state === 'ready' || request?.state === 'using';

    return (
        <div data-test="alt-text-suggestion" className="grid gap-2">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <Button
                    ref={suggestButton}
                    type="button"
                    size="sm"
                    variant="neutral"
                    data-test="alt-text-suggest"
                    onClick={() => void ask()}
                >
                    <Sparkles aria-hidden="true" />
                    {altTextWords().suggest}
                </Button>
                <span className="text-ink-2 text-small">
                    {__('The image is sent to :providers.', {
                        providers: providerList(offer.sentTo),
                    })}
                </span>
            </div>
            {request !== null && (
                <section
                    aria-labelledby={heading}
                    data-test="alt-text-card"
                    className="bg-surface-2 text-small grid gap-2 rounded-md border p-3"
                >
                    <h3 id={heading} className="text-caption font-medium">
                        {altTextWords().heading}
                    </h3>
                    <p
                        aria-live="polite"
                        className={
                            working ? 'flex items-center gap-2' : 'sr-only'
                        }
                    >
                        {working ? (
                            <>
                                <Spinner /> {__('Working…')}
                            </>
                        ) : proposing ? (
                            altTextWords().ready
                        ) : (
                            ''
                        )}
                    </p>
                    {working && (
                        <div>
                            <Button
                                type="button"
                                size="sm"
                                variant="neutral"
                                data-test="ai-cancel"
                                onClick={putAsideFromCard}
                            >
                                {__('Cancel')}
                            </Button>
                        </div>
                    )}
                    {working && request.slow && (
                        <p className="text-ink-2 text-small">
                            {ASSISTANT_WORDS.slow}
                        </p>
                    )}
                    {proposing && (
                        <Proposal
                            before={request.before}
                            after={request.suggestion}
                            busy={request.state === 'using'}
                            onUse={() => void applySuggestion()}
                            onDiscard={putAsideFromCard}
                        />
                    )}
                    {request.message !== null && (
                        <p role="alert" className="text-danger-ink">
                            {request.message}
                        </p>
                    )}
                    {request.state === 'failed' && (
                        <div>
                            <Button
                                type="button"
                                size="sm"
                                variant="neutral"
                                data-test="ai-dismiss"
                                onClick={putAsideFromCard}
                            >
                                {__('Dismiss')}
                            </Button>
                        </div>
                    )}
                    {request.sentTo.length > 0 && (
                        <p className="text-ink-2 text-small">
                            {__('Sent to :providers', {
                                providers: request.sentTo.join(
                                    ` ${__('and')} `,
                                ),
                            })}
                        </p>
                    )}
                </section>
            )}
        </div>
    );
}

import { Link } from '@inertiajs/react';
import { Proposal } from '@/components/ai/proposal';
import {
    ASSISTANT_WORDS,
    useAssistant,
} from '@/components/editor/assistant/assistant-context';
import type { AssistantRequest } from '@/components/editor/assistant/assistant-context';
import { FieldsProposed } from '@/components/editor/rail/fields-proposed';
import { SuggestMetadataPane } from '@/components/editor/rail/suggest-metadata';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { __ } from '@/lib/i18n';
import { show as showSettings } from '@/routes/settings';
import type { EditorAi } from '@/types';

/** "Anthropic", "Anthropic or OpenAI", "Anthropic, OpenAI or DeepSeek". */
export function providerList(names: string[]): string {
    return names.length <= 1
        ? (names[0] ?? '')
        : `${names.slice(0, -1).join(', ')} or ${names[names.length - 1]}`;
}

/**
 * The assistant pane (I-190, I-232): each task's section, the request's
 * card while there is one, where the text goes (I-1304), and its shortcut.
 * Nothing changes until the person chooses Use (I-317).
 */
export function AssistantPanel({ ai }: { ai: EditorAi }) {
    const { request, use, putAside } = useAssistant();

    return (
        <div className="text-small grid gap-4">
            {ai.tasks.some((task) => task.key === 'rewrite') && (
                <p className="text-ink-2">
                    {__(
                        'To rewrite text, select it within one paragraph and choose Rewrite in the menu that appears.',
                    )}
                </p>
            )}

            <SuggestMetadataPane ai={ai} />

            {request === null ? (
                <p className="text-ink-2">
                    {__(
                        'Ask for a suggestion and it appears here. Nothing changes until you choose Use, and using a suggestion is one change you can undo.',
                    )}
                </p>
            ) : (
                <RequestCard
                    request={request}
                    onUse={() => void use()}
                    onPutAside={() => void putAside()}
                />
            )}

            <p className="text-ink-2 text-caption">
                {__('What you ask about is sent to :providers.', {
                    providers: providerList(ai.sentTo),
                })}
                {ai.canManage && (
                    <>
                        {' '}
                        <Link
                            href={showSettings({ group: 'ai' }).url}
                            className="underline underline-offset-2"
                        >
                            {__('Change this in Settings › AI')}
                        </Link>
                    </>
                )}
            </p>
            <p className="text-ink-2 text-caption">
                {__('Ctrl + J or ⌘ J opens and closes this pane.')}
            </p>
        </div>
    );
}

function RequestCard({
    request,
    onUse,
    onPutAside,
}: {
    request: AssistantRequest;
    onUse: () => void;
    onPutAside: () => void;
}) {
    const working = request.state === 'asking' || request.state === 'working';
    const proposing = request.state === 'ready' || request.state === 'using';

    return (
        <section
            aria-labelledby="assistant-request"
            className="bg-surface-2 grid gap-2 rounded-md border p-3"
        >
            <h3 id="assistant-request" className="text-caption font-medium">
                {request.scope}
            </h3>
            {working && (
                <>
                    <div className="flex items-center justify-between gap-2">
                        <p
                            aria-live="polite"
                            className="flex items-center gap-2"
                        >
                            <Spinner /> {__('Working…')}
                        </p>
                        <Button
                            size="sm"
                            variant="outline"
                            data-test="ai-cancel"
                            onClick={onPutAside}
                        >
                            {__('Cancel')}
                        </Button>
                    </div>
                    {request.slow && (
                        <p className="text-ink-2 text-caption">
                            {ASSISTANT_WORDS.slow}
                        </p>
                    )}
                </>
            )}
            {proposing &&
                (request.values.text !== undefined ? (
                    <Proposal
                        before={request.before}
                        after={request.values.text}
                        busy={request.state === 'using'}
                        onUse={onUse}
                        onDiscard={onPutAside}
                    />
                ) : (
                    <FieldsProposed
                        handles={Object.keys(request.values)}
                        onDiscard={onPutAside}
                    />
                ))}
            {request.message !== null && (
                <p role="alert" className="text-danger-ink">
                    {request.message}
                </p>
            )}
            {request.state === 'failed' && (
                <div>
                    <Button
                        size="sm"
                        variant="outline"
                        data-test="ai-dismiss"
                        onClick={onPutAside}
                    >
                        {__('Dismiss')}
                    </Button>
                </div>
            )}
            {request.sentTo.length > 0 && (
                <p className="text-ink-2 text-caption">
                    {__('Sent to :providers', {
                        providers: request.sentTo.join(' and '),
                    })}
                </p>
            )}
        </section>
    );
}

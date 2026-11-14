import { Button } from '@/components/ui/button';
import { wordDiff } from './word-diff';

/**
 * A proposal beside what it would replace (I-317): the original with the
 * words it removes struck, the proposal with the words it adds marked, then
 * Use and Discard. Shared by the assistant pane and the media details.
 */
export function Proposal({
    before,
    after,
    busy = false,
    onUse,
    onDiscard,
}: {
    before: string | null;
    after: string;
    busy?: boolean;
    onUse: () => void;
    onDiscard: () => void;
}) {
    if (before !== null && before.trim() === after.trim()) {
        return (
            <div className="grid gap-2">
                <p>No change suggested. It already reads well.</p>
                <div>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        data-test="ai-dismiss"
                        onClick={onDiscard}
                    >
                        Dismiss
                    </Button>
                </div>
            </div>
        );
    }

    const { removed, added } = wordDiff(before ?? '', after);

    return (
        <div className="grid gap-2">
            <p>
                <span className="sr-only">Current: </span>
                {before === null || before === ''
                    ? '(empty)'
                    : removed.map((part, index) =>
                          part.changed ? (
                              <del
                                  key={index}
                                  className="bg-destructive/10 text-destructive line-through"
                              >
                                  {part.text}
                              </del>
                          ) : (
                              <span key={index}>{part.text}</span>
                          ),
                      )}
            </p>
            <p>
                <span className="sr-only">Proposed: </span>
                {added.map((part, index) =>
                    part.changed ? (
                        <ins
                            key={index}
                            className="bg-success-muted text-success underline"
                        >
                            {part.text}
                        </ins>
                    ) : (
                        <span key={index}>{part.text}</span>
                    ),
                )}
            </p>
            <div className="flex gap-2">
                <Button
                    type="button"
                    size="sm"
                    data-test="ai-use"
                    disabled={busy}
                    onClick={onUse}
                >
                    {busy ? 'Using…' : 'Use'}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    data-test="ai-discard"
                    disabled={busy}
                    onClick={onDiscard}
                >
                    Discard
                </Button>
            </div>
        </div>
    );
}

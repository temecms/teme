import { Button } from '@/components/ui/button';
import { characterCount, useMetadata } from './use-metadata';
import type { MetadataBinding } from './use-metadata';

/** A field's label inside a sentence: "SEO title", "meta description". */
function inSentence(label: string): string {
    return /^[A-Z]{2}/.test(label)
        ? label
        : label.charAt(0).toLowerCase() + label.slice(1);
}

/**
 * One field's proposal, directly under the field (I-1313): the proposed
 * text, its length and Use. Drawn only while the metadata request offers
 * this field something it doesn't hold.
 */
export function MetadataProposal({
    handle,
    label,
    metadata,
}: {
    handle: string;
    label: string;
    metadata: MetadataBinding;
}) {
    const { proposals, busy, message, use } = useMetadata(metadata);
    const proposal = proposals[handle] as string | undefined;

    if (proposal === undefined) {
        return null;
    }

    const field = inSentence(label);

    return (
        <div
            role="group"
            aria-label={`Proposed ${field}`}
            data-ai-proposal={handle}
            className="bg-muted grid gap-1 rounded-md border p-3 text-[13px]"
        >
            <p>
                <span className="text-muted-foreground">Proposed: </span>
                {proposal}
            </p>
            <div className="flex items-center justify-between gap-2">
                <p className="text-muted-foreground text-xs">
                    {characterCount(proposal)}
                </p>
                <Button
                    size="sm"
                    data-test="metadata-use"
                    aria-label={`Use the proposed ${field}`}
                    disabled={busy}
                    onClick={() => void use([handle])}
                >
                    {busy ? 'Using…' : 'Use'}
                </Button>
            </div>
            {message !== null && (
                <p role="alert" className="text-destructive text-xs">
                    {message}
                </p>
            )}
        </div>
    );
}

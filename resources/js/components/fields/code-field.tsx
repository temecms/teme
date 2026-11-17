import { Button } from '@/components/ui/button';
import { StatusPill } from '@/components/ui/status-pill';
import { Textarea } from '@/components/ui/textarea';
import { __ } from '@/lib/i18n';

/** One of a code field's inline scripts that waits for approval. */
export type WaitingScript = { id: string; script: string };

/**
 * A code field (docs/plans/plugin-sockets-2.md, A2, A5): markup in a plain
 * monospace box, never run or drawn here. Under a field that may hold
 * scripts, each that waits for approval: a person who may change site code
 * approves it there, anyone else sees that it waits (the decided Q2).
 */
export function CodeField({
    id,
    name,
    value,
    defaultValue,
    onChange,
    describedBy,
    invalid,
    waiting = [],
    approve,
    busy = false,
}: {
    id: string;
    name?: string;
    value?: string;
    defaultValue?: string;
    onChange?: (value: string) => void;
    describedBy?: string;
    invalid?: boolean;
    /** This field's scripts that wait for approval. */
    waiting?: WaitingScript[];
    /** Approves one; left out for a person who may not. */
    approve?: (id: string) => void;
    busy?: boolean;
}) {
    return (
        <div className="grid gap-2">
            <Textarea
                id={id}
                name={name}
                value={value}
                defaultValue={defaultValue}
                onChange={(event) => onChange?.(event.target.value)}
                aria-describedby={describedBy}
                aria-invalid={invalid || undefined}
                spellCheck={false}
                autoCapitalize="off"
                autoCorrect="off"
                rows={6}
                className="text-small font-mono"
                data-test="code-field"
            />
            {waiting.length > 0 && (
                <ul
                    className="grid gap-2"
                    aria-label={__('Scripts waiting for approval')}
                    data-test="code-field-waiting"
                >
                    {waiting.map((script) => (
                        <li
                            key={script.id}
                            className="bg-surface-2 grid gap-2 rounded-md p-3"
                            data-test="waiting-script"
                        >
                            <pre className="text-small text-ink-2 font-mono break-all whitespace-pre-wrap">
                                {script.script}
                            </pre>
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusPill tone="warn">
                                    {__('Waiting for approval')}
                                </StatusPill>
                                {approve !== undefined && (
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        disabled={busy}
                                        onClick={() => approve(script.id)}
                                        data-test="approve-script"
                                    >
                                        {__('Approve script')}
                                    </Button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

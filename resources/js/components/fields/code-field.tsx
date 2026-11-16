import { Textarea } from '@/components/ui/textarea';
import { __n } from '@/lib/i18n';

/**
 * A code field (docs/plans/plugin-sockets-2.md, A2, A5): markup in a plain
 * monospace box, never run or drawn here. A field that may hold scripts
 * says, under it, how many wait for a person who may change site code.
 */
export function CodeField({
    id,
    name,
    value,
    defaultValue,
    onChange,
    describedBy,
    invalid,
    waiting = 0,
}: {
    id: string;
    name?: string;
    value?: string;
    defaultValue?: string;
    onChange?: (value: string) => void;
    describedBy?: string;
    invalid?: boolean;
    /** How many of this field's scripts wait for approval. */
    waiting?: number;
}) {
    return (
        <div className="grid gap-1">
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
            {waiting > 0 && (
                <p
                    className="text-small text-warn-ink"
                    data-test="code-field-waiting"
                >
                    {__n(
                        ':count script waits for an administrator’s approval in Settings › Site code.|:count scripts wait for an administrator’s approval in Settings › Site code.',
                        waiting,
                        { count: waiting },
                    )}
                </p>
            )}
        </div>
    );
}

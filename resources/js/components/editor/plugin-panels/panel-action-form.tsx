import { useId, useState } from 'react';
import EditorPanelController from '@/actions/App/Http/Admin/Contents/EditorPanelController';
import { send } from '@/components/editor/editor-http';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SelectField } from '@/components/ui/select-field';
import { Textarea } from '@/components/ui/textarea';
import { __ } from '@/lib/i18n';
import type {
    EditorPluginPanel,
    PanelAction,
    PanelAnswer,
    PanelField,
} from '@/types';
import { PANEL_WORDS } from './panel-words';

type Value = string | number | boolean | null;

/** Each field's starting value: its default, else empty. */
function starting(fields: PanelField[]): Record<string, Value> {
    return Object.fromEntries(
        fields.map((field) => [
            field.key,
            field.default ?? (field.type === 'boolean' ? false : null),
        ]),
    );
}

/**
 * One of a panel's actions: its small form, when it declares one, and its
 * button. A declared confirmation is asked first, in place. The action
 * saves what is typed, then queues the plugin's work on that revision as
 * this person.
 */
export function PanelActionForm({
    action,
    panel,
    contentId,
    busy,
    checkpoint,
    onStale,
    onAnswer,
}: {
    action: PanelAction;
    panel: EditorPluginPanel;
    contentId: string;
    busy: boolean;
    checkpoint: () => Promise<string | null>;
    onStale: (answer: Record<string, unknown>) => void;
    onAnswer: (answer: PanelAnswer) => void;
}) {
    const id = useId();
    const [values, setValues] = useState<Record<string, Value>>(() =>
        starting(action.fields),
    );
    const [confirming, setConfirming] = useState(false);
    const [sending, setSending] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [problem, setProblem] = useState<string | null>(null);

    const set = (key: string, value: Value) =>
        setValues((was) => ({ ...was, [key]: value }));

    const run = async () => {
        setConfirming(false);
        setSending(true);
        setProblem(null);
        setErrors({});

        try {
            const revisionId = await checkpoint();

            if (revisionId === null) {
                setProblem(__(PANEL_WORDS.notSaved));

                return;
            }

            const answer = await send(
                EditorPanelController.action({
                    content: contentId,
                    code: panel.code,
                    panel: panel.id,
                    action: action.id,
                }),
                { revision_id: revisionId, values },
            );

            if (answer.status === 200) {
                onAnswer(answer.body as unknown as PanelAnswer);
            } else if (answer.status === 409 && answer.body.stale === true) {
                onStale(answer.body);
            } else if (answer.status === 422) {
                const found = (answer.body.errors ?? {}) as Record<
                    string,
                    string[]
                >;
                const byField: Record<string, string> = {};

                for (const [key, messages] of Object.entries(found)) {
                    byField[key.replace(/^values\./, '')] = messages[0] ?? '';
                }

                setErrors(byField);
                setProblem(byField.values ?? byField.action ?? null);
            } else {
                setProblem(__(PANEL_WORDS.unexpected));
            }
        } catch {
            setProblem(__(PANEL_WORDS.offline));
        } finally {
            setSending(false);
        }
    };

    return (
        <form
            className="grid gap-3"
            data-test={`panel-action-${action.id}`}
            onSubmit={(event) => {
                event.preventDefault();

                if (action.confirm !== null) {
                    setConfirming(true);
                } else {
                    void run();
                }
            }}
        >
            {action.fields.map((field) => (
                <PanelFieldControl
                    key={field.key}
                    id={`${id}-${field.key}`}
                    field={field}
                    value={values[field.key] ?? null}
                    error={errors[field.key]}
                    onChange={(value) => set(field.key, value)}
                />
            ))}

            {confirming ? (
                <div
                    role="alertdialog"
                    aria-labelledby={`${id}-confirm`}
                    className="bg-surface-2 grid gap-3 rounded-md p-3"
                >
                    <p id={`${id}-confirm`} className="text-ink">
                        {action.confirm}
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            size="sm"
                            data-test="panel-action-confirm"
                            onClick={() => void run()}
                        >
                            {action.label}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => setConfirming(false)}
                        >
                            {__('Cancel')}
                        </Button>
                    </div>
                </div>
            ) : (
                <div>
                    <Button
                        type="submit"
                        size="sm"
                        variant="neutral"
                        loading={sending}
                        disabled={sending || busy}
                    >
                        {action.label}
                    </Button>
                </div>
            )}

            {problem !== null && (
                <p role="alert" className="text-danger-ink">
                    {problem}
                </p>
            )}
        </form>
    );
}

/** One field of an action's form, drawn as core's own fields are. */
function PanelFieldControl({
    id,
    field,
    value,
    error,
    onChange,
}: {
    id: string;
    field: PanelField;
    value: Value;
    error?: string;
    onChange: (value: Value) => void;
}) {
    if (field.type === 'boolean') {
        return (
            <div className="grid gap-1">
                <div className="flex items-center gap-2">
                    <Checkbox
                        id={id}
                        checked={value === true}
                        onCheckedChange={(checked) =>
                            onChange(checked === true)
                        }
                    />
                    <Label htmlFor={id}>{field.label}</Label>
                </div>
                {error !== undefined && (
                    <p className="text-danger-ink">{error}</p>
                )}
            </div>
        );
    }

    return (
        <Field
            htmlFor={id}
            label={field.label}
            required={field.required}
            error={error}
        >
            {field.type === 'select' ? (
                <SelectField
                    id={id}
                    options={field.options}
                    emptyLabel={field.required ? undefined : __('None')}
                    value={value === null ? '' : String(value)}
                    onValueChange={(next) =>
                        onChange(next === '' ? null : next)
                    }
                />
            ) : field.type === 'textarea' ? (
                <Textarea
                    id={id}
                    rows={3}
                    maxLength={field.maxLength ?? undefined}
                    required={field.required}
                    value={typeof value === 'string' ? value : ''}
                    onChange={(event) => onChange(event.target.value)}
                />
            ) : field.type === 'number' ? (
                <Input
                    id={id}
                    type="number"
                    inputMode="decimal"
                    min={field.min ?? undefined}
                    max={field.max ?? undefined}
                    required={field.required}
                    value={typeof value === 'number' ? String(value) : ''}
                    onChange={(event) =>
                        onChange(
                            event.target.value === ''
                                ? null
                                : Number(event.target.value),
                        )
                    }
                />
            ) : (
                <Input
                    id={id}
                    maxLength={field.maxLength ?? undefined}
                    required={field.required}
                    value={typeof value === 'string' ? value : ''}
                    onChange={(event) => onChange(event.target.value)}
                />
            )}
        </Field>
    );
}

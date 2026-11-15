import { useState } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { SelectField } from '@/components/ui/select-field';
import { formatCost } from '@/lib/ai-cost';
import type { AiModelOption, AiTaskRow } from '@/types/ai-settings';

/** The lines under a select: what the chosen model costs, how it answers, and whether its service has a key. */
function modelHint(option: AiModelOption | undefined, stale: boolean): string {
    if (stale) {
        return 'No longer offered. Choose another.';
    }

    if (option === undefined) {
        return '';
    }

    const lines = [
        option.input === null || option.output === null
            ? 'Its price isn’t known, so its cost shows as unknown and the budget can’t count it.'
            : `${formatCost(option.input)} a million tokens sent, ${formatCost(option.output)} a million written.`,
    ];

    if (option.note !== null) {
        lines.push(option.note);
    }

    if (!option.ready) {
        lines.push(`${option.provider} has no key yet.`);
    }

    return lines.join(' ');
}

/**
 * One of a task's two selects (I-1308): the models able to do the task, and
 * a stored choice that is no longer among them kept as itself and not sent,
 * so the select never changes a value on its own.
 */
function ModelSelect({
    id,
    label,
    emptyLabel,
    name,
    value,
    onChange,
    options,
    error,
}: {
    id: string;
    label: string;
    emptyLabel: string;
    name: string;
    value: string;
    onChange: (value: string) => void;
    options: AiModelOption[];
    error?: string;
}) {
    const chosen = options.find((option) => option.value === value);
    const stale = value !== '' && chosen === undefined;
    const choices = [
        ...(stale
            ? [{ value, label: 'Chosen model (no longer offered)' }]
            : []),
        ...options.map((option) => ({
            value: option.value,
            label: option.ready ? option.label : `${option.label} (no key yet)`,
        })),
    ];

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            {/* A choice no longer offered isn't sent, so a save leaves it as it is (I-1308). */}
            <SelectField
                id={id}
                name={stale ? undefined : name}
                value={value}
                onValueChange={onChange}
                emptyLabel={emptyLabel}
                options={choices}
                aria-describedby={`${id}-hint ${id}-error`}
            />
            <p id={`${id}-hint`} className="text-small text-ink-2">
                {modelHint(chosen, stale)}
            </p>
            <InputError id={`${id}-error`} message={error} />
        </div>
    );
}

type RowProps = {
    task: AiTaskRow;
    values: Record<string, unknown>;
    errors: Record<string, string>;
};

/** A stored choice as the form holds it: its text, or empty. */
function stored(values: Record<string, unknown>, field: string): string {
    const value = values[field];

    return typeof value === 'string' ? value : '';
}

/**
 * One task on Settings › AI (I-1308): its name, whether it is on and, if
 * not, why, its note, and its model and fallback selects.
 */
export function TaskRow({ task, values, errors }: RowProps) {
    const [model, setModel] = useState(stored(values, task.modelField));
    const [fallback, setFallback] = useState(
        stored(values, task.fallbackField),
    );

    return (
        <section
            aria-labelledby={`ai-task-${task.key}-heading`}
            data-test={`ai-task-${task.key}`}
            className="border-line grid gap-3 border-t pt-4"
        >
            <div className="grid gap-1">
                <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <h3
                        id={`ai-task-${task.key}-heading`}
                        className="text-body font-medium"
                    >
                        {task.label}
                    </h3>
                    <span
                        data-test={`ai-task-status-${task.key}`}
                        className={
                            task.on
                                ? 'text-ink text-small'
                                : 'text-small text-ink-2'
                        }
                    >
                        {task.status}
                    </span>
                </div>
                {task.note !== null && (
                    <p className="text-small text-ink-2">{task.note}</p>
                )}
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <ModelSelect
                    id={`ai-model-${task.key}`}
                    label="Model"
                    emptyLabel="None (off)"
                    name={`values[${task.modelField}]`}
                    value={model}
                    onChange={setModel}
                    options={task.options}
                    error={errors[`values.${task.modelField}`]}
                />
                <ModelSelect
                    id={`ai-fallback-${task.key}`}
                    label="Fallback"
                    emptyLabel="No fallback"
                    name={`values[${task.fallbackField}]`}
                    value={fallback}
                    onChange={setFallback}
                    options={task.options}
                    error={errors[`values.${task.fallbackField}`]}
                />
            </div>
        </section>
    );
}

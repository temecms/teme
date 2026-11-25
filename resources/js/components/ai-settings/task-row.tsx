import { useState } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { SelectField } from '@/components/ui/select-field';
import { formatCost } from '@/lib/ai-cost';
import { __ } from '@/lib/i18n';
import type { AiModelOption, AiTaskRow } from '@/types/ai-settings';

/** The lines under a select: what the chosen model costs, how it answers, and whether its service has a key. */
function modelHint(option: AiModelOption | undefined, stale: boolean): string {
    if (stale) {
        return __('No longer offered. Choose another.');
    }

    if (option === undefined) {
        return '';
    }

    const lines = [
        option.input === null || option.output === null
            ? __(
                  'Its price isn’t known, so its cost shows as unknown and the budget can’t count it.',
              )
            : __(':sent a million tokens sent, :written a million written.', {
                  sent: formatCost(option.input),
                  written: formatCost(option.output),
              }),
    ];

    if (option.note !== null) {
        lines.push(option.note);
    }

    if (!option.ready) {
        lines.push(
            __(':service has no key yet.', { service: option.provider }),
        );
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
    hint,
    error,
}: {
    id: string;
    label: string;
    emptyLabel: string;
    name: string;
    value: string;
    onChange: (value: string) => void;
    options: AiModelOption[];
    /** The line under the select, before what the chosen model costs. */
    hint: string;
    error?: string;
}) {
    const chosen = options.find((option) => option.value === value);
    const stale = value !== '' && chosen === undefined;
    const choices = [
        ...(stale
            ? [{ value, label: __('Chosen model (no longer offered)') }]
            : []),
        ...options.map((option) => ({
            value: option.value,
            label: option.ready
                ? option.label
                : __(':model (no key yet)', { model: option.label }),
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
                className="w-full"
            />
            <p id={`${id}-hint`} className="text-caption text-ink-3">
                {[hint, modelHint(chosen, stale)]
                    .filter((line) => line !== '')
                    .join(' ')}
            </p>
            <InputError id={`${id}-error`} message={error} />
        </div>
    );
}

type RowProps = {
    task: AiTaskRow;
    values: Record<string, unknown>;
    errors: Record<string, string>;
    /** Words after the model's hint, such as what a plugin's task sends. */
    note?: string | null;
};

/** A stored choice as the form holds it: its text, or empty. */
function stored(values: Record<string, unknown>, field: string): string {
    const value = values[field];

    return typeof value === 'string' ? value : '';
}

/**
 * One task on Settings › AI (I-1308): the model select, then its fallback
 * at the same level under it, each full width with its hint.
 */
export function TaskRow({ task, values, errors, note = null }: RowProps) {
    const [model, setModel] = useState(stored(values, task.modelField));
    const [fallback, setFallback] = useState(
        stored(values, task.fallbackField),
    );

    return (
        <div
            data-test={`ai-task-${task.key}`}
            className="col-span-full grid gap-4"
        >
            <ModelSelect
                id={`ai-model-${task.key}`}
                label={__(':task: model', { task: task.label })}
                emptyLabel={__('Off: :task is not available', {
                    task: task.label,
                })}
                name={`values[${task.modelField}]`}
                value={model}
                onChange={setModel}
                options={task.options}
                hint={[
                    __(
                        'The provider and model that answer. Empty switches :task off.',
                        { task: task.label },
                    ),
                    note ?? '',
                ]
                    .filter((line) => line !== '')
                    .join(' ')}
                error={errors[`values.${task.modelField}`]}
            />
            <ModelSelect
                id={`ai-fallback-${task.key}`}
                label={__(':task: fallback', { task: task.label })}
                emptyLabel={__('No fallback')}
                name={`values[${task.fallbackField}]`}
                value={fallback}
                onChange={setFallback}
                options={task.options}
                hint={__('Optional.')}
                error={errors[`values.${task.fallbackField}`]}
            />
        </div>
    );
}

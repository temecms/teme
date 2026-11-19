import { Form } from '@inertiajs/react';
import type { FormComponentRef, FormDataConvertible } from '@inertiajs/core';
import { useEffect, useRef, useState } from 'react';
import type { ComponentProps, ReactNode } from 'react';
import { FreshPasswordDialog } from '@/components/patterns/fresh-password';
import { SaveBar } from '@/components/patterns/save-bar';
import { useSettingsFrame } from '@/components/patterns/settings-frame';
import { __ } from '@/lib/i18n';
import { useSettingsDirty } from './use-settings-dirty';

type FormProps = ComponentProps<typeof Form>;

type Props = {
    /** Where the form saves: a Wayfinder `.form()` result. */
    action: { action: string; method: FormProps['method'] };
    /** Not shown: every settings page's bar says "Save" (v10). Kept so older callers still compile. */
    saveLabel?: string;
    /** The Save's `data-test`. */
    saveTestId?: string;
    /** What is unsaved, for the question before leaving. */
    what: string;
    /** A sensitive group: the password is asked for in place. */
    askInPlace?: boolean;
    /** View only: the fields are shown but nothing can be saved, so the form has no save bar. */
    readOnly?: boolean;
    /** The changed settings' names, from a field's form name, for the password prompt. */
    labelFor?: (name: string) => string | null;
    /** What is sent, made from the form's data. */
    transform?: (
        data: Record<string, FormDataConvertible>,
    ) => Record<string, FormDataConvertible>;
    options?: FormProps['options'];
    /** After a save succeeds, such as Addresses following its moves. */
    onSuccess?: () => void;
    /** For a control outside the form that must know of unsaved changes. */
    onDirtyChange?: (dirty: boolean) => void;
    className?: string;
    children: (slot: {
        errors: Record<string, string>;
        processing: boolean;
    }) => ReactNode;
};

/**
 * The form of a settings page (docs/architecture/design-system.md, 4.12):
 * its sections, then the one save bar, shown once anything changes, with
 * its leave guard. Discard draws the form afresh from the page's props, and
 * a sensitive group asks for the password in place and saves what was typed.
 */
export function SettingsForm(props: Props) {
    const [drawn, setDrawn] = useState(0);

    return (
        <SettingsFormBody
            key={drawn}
            {...props}
            onDiscard={() => setDrawn((count) => count + 1)}
        />
    );
}

/** A field's label as the page draws it, from its form name (`values[key]`, `secrets[key]`). */
function drawnLabel(name: string): string | null {
    const key = /^(?:values|secrets)\[([^\]]+)\]/.exec(name)?.[1];
    const label =
        key === undefined
            ? null
            : document.querySelector(`label[for="field-${CSS.escape(key)}"]`);

    return label === null
        ? null
        : (label.firstChild?.textContent ?? label.textContent ?? '').trim() ||
              null;
}

/** "X", "X and Y", "X, Y and 2 more". */
function namesOf(labels: string[]): string {
    if (labels.length > 2) {
        return __(':first, :second and :count more', {
            first: labels[0],
            second: labels[1],
            count: labels.length - 2,
        });
    }

    return labels.join(` ${__('and')} `);
}

function SettingsFormBody({
    action,
    saveTestId,
    what,
    askInPlace = false,
    readOnly = false,
    labelFor = drawnLabel,
    transform,
    options,
    onSuccess,
    onDirtyChange,
    className,
    children,
    onDiscard,
}: Props & { onDiscard: () => void }) {
    const anchor = useRef<HTMLSpanElement>(null);
    const form = useRef<FormComponentRef>(null);
    const [asking, setAsking] = useState(false);
    const { dirty, changed, settle } = useSettingsDirty(anchor);
    const frame = useSettingsFrame();
    const [asked, setAsked] = useState<string[]>([]);

    useEffect(() => {
        onDirtyChange?.(dirty);

        // "Saved just now" stays only until the next change.
        if (dirty) {
            frame?.setSaved(false);
        }
    }, [dirty, onDirtyChange, frame]);

    return (
        <>
            <Form
                {...action}
                ref={form}
                transform={
                    transform === undefined
                        ? undefined
                        : (data) =>
                              transform(
                                  data as Record<string, FormDataConvertible>,
                              )
                }
                options={{ ...options, preserveScroll: true }}
                headers={
                    askInPlace ? { 'X-Teme-Ask-In-Place': '1' } : undefined
                }
                onHttpException={(response) => {
                    if (askInPlace && response.status === 423) {
                        setAsked(
                            changed
                                .map(labelFor)
                                .filter(
                                    (label): label is string => label !== null,
                                ),
                        );
                        setAsking(true);

                        return false;
                    }
                }}
                onSuccess={() => {
                    window.requestAnimationFrame(settle);
                    frame?.setSaved(true);
                    onSuccess?.();
                }}
                className={className ?? 'grid gap-4'}
            >
                {({ errors, processing }) => (
                    <>
                        <span ref={anchor} hidden />
                        {children({ errors, processing })}
                        {!readOnly && (
                            <SaveBar
                                dirty={dirty}
                                processing={processing}
                                onDiscard={onDiscard}
                                what={what}
                                testId={saveTestId ?? 'settings-save'}
                                changes={changed.length}
                                problems={
                                    Object.keys(errors).filter(
                                        (key) => key !== 'expected_version',
                                    ).length
                                }
                            />
                        )}
                    </>
                )}
            </Form>
            {askInPlace && (
                <FreshPasswordDialog
                    open={asking}
                    onOpenChange={setAsking}
                    title={__('Confirm your password')}
                    text={
                        asked.length > 0
                            ? __(
                                  'You changed :names. Changes like this ask for your password. Teme asks again after 15 minutes.',
                                  { names: namesOf(asked) },
                              )
                            : __(
                                  'Changes like this ask for your password. Teme asks again after 15 minutes.',
                              )
                    }
                    confirmLabel={__('Confirm and save')}
                    onConfirmed={() => form.current?.submit()}
                />
            )}
        </>
    );
}

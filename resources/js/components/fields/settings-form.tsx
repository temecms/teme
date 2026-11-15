import { Form } from '@inertiajs/react';
import type { FormComponentRef, FormDataConvertible } from '@inertiajs/core';
import { useEffect, useRef, useState } from 'react';
import type { ComponentProps, ReactNode } from 'react';
import { FreshPasswordDialog } from '@/components/patterns/fresh-password';
import { SaveBar } from '@/components/patterns/save-bar';
import { __ } from '@/lib/i18n';
import { useSettingsDirty } from './use-settings-dirty';

type FormProps = ComponentProps<typeof Form>;

type Props = {
    /** Where the form saves: a Wayfinder `.form()` result. */
    action: { action: string; method: FormProps['method'] };
    /** The bar's Save, such as "Save general settings". */
    saveLabel: string;
    /** The Save's `data-test`. */
    saveTestId?: string;
    /** What is unsaved, for the question before leaving. */
    what: string;
    /** A sensitive group: the password is asked for in place. */
    askInPlace?: boolean;
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

function SettingsFormBody({
    action,
    saveLabel,
    saveTestId,
    what,
    askInPlace = false,
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
    const { dirty, settle } = useSettingsDirty(anchor);

    useEffect(() => {
        onDirtyChange?.(dirty);
    }, [dirty, onDirtyChange]);

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
                        setAsking(true);

                        return false;
                    }
                }}
                onSuccess={() => {
                    window.requestAnimationFrame(settle);
                    onSuccess?.();
                }}
                className={className ?? 'grid gap-4'}
            >
                {({ errors, processing }) => (
                    <>
                        <span ref={anchor} hidden />
                        {children({ errors, processing })}
                        <SaveBar
                            dirty={dirty}
                            processing={processing}
                            onDiscard={onDiscard}
                            saveLabel={saveLabel}
                            what={what}
                            testId={saveTestId ?? 'settings-save'}
                        />
                    </>
                )}
            </Form>
            {askInPlace && (
                <FreshPasswordDialog
                    open={asking}
                    onOpenChange={setAsking}
                    title={__('Confirm your password')}
                    onConfirmed={() => form.current?.submit()}
                />
            )}
        </>
    );
}

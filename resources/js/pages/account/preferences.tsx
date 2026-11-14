import { Form, Head, setLayoutProps } from '@inertiajs/react';
import type { FormComponentRef } from '@inertiajs/core';
import { useRef } from 'react';
import PreferencesController from '@/actions/App/Http/Admin/Account/PreferencesController';
import { SettingsField } from '@/components/fields/settings-field';
import { FormPanel } from '@/components/patterns/form-page';
import { SettingsPage } from '@/components/patterns/settings-page';
import { Field } from '@/components/ui/field';
import { SelectField } from '@/components/ui/select-field';
import { useAppearance, type Appearance } from '@/hooks/use-appearance';
import { __ } from '@/lib/i18n';
import { edit as editPreferences } from '@/routes/preferences';
import type { FieldDefinition } from '@/types';

type Props = {
    /** Every declared preference but the lists, in the settings screen's field shape. */
    fields: FieldDefinition[];
    /** This person's values, defaults applied. */
    values: Record<string, unknown>;
};

/** Drawn on its own: the light or dark switch saves itself. */
const MODE = 'appearance.mode';

/** The panels, by the key's first part; any other key goes under More, in the registry's order. */
const PANELS: { prefix: string; title: () => string }[] = [
    { prefix: 'lists.', title: () => __('Lists') },
    { prefix: 'editor.', title: () => __('Writing') },
    { prefix: 'dashboard.', title: () => __('Home') },
];

/** A field the person types into, saved once it is left rather than at each key; a box, list or switch is saved as it is chosen. */
function typed(
    target: EventTarget,
): target is HTMLInputElement | HTMLTextAreaElement {
    return (
        target instanceof HTMLTextAreaElement ||
        (target instanceof HTMLInputElement &&
            !['checkbox', 'radio', 'hidden', 'file', 'range', 'color'].includes(
                target.type,
            ))
    );
}

/**
 * The person's own preferences (I-110), drawn from the registry with the
 * settings screen's components, each saved as it is chosen. They change
 * nothing for anyone else.
 */
export default function Preferences({ fields, values }: Props) {
    const { appearance, updateAppearance } = useAppearance();
    const form = useRef<FormComponentRef | null>(null);
    const typedAtFocus = useRef<string | null>(null);
    const others = fields.filter((field) => field.key !== MODE);
    const panels = [
        ...PANELS.map((panel) => ({
            title: panel.title(),
            fields: others.filter((field) =>
                field.key.startsWith(panel.prefix),
            ),
        })),
        {
            title: __('More'),
            fields: others.filter(
                (field) =>
                    !PANELS.some((panel) => field.key.startsWith(panel.prefix)),
            ),
        },
    ].filter((panel) => panel.fields.length > 0);

    setLayoutProps({
        breadcrumbs: [
            {
                title: __('Preferences'),
                href: editPreferences(),
            },
        ],
    });

    return (
        <>
            <Head title={__('Preferences')} />

            <SettingsPage
                title={__('Preferences')}
                description={__(
                    'How the admin looks and behaves for you. Each choice is saved as you make it.',
                )}
            >
                <FormPanel title={__('Appearance')}>
                    <Field
                        htmlFor="appearance-mode"
                        label={__('Appearance')}
                        help={__('Saved as you choose.')}
                    >
                        <SelectField
                            id="appearance-mode"
                            options={[
                                {
                                    value: 'system',
                                    label: __('Match the system'),
                                },
                                { value: 'light', label: __('Light') },
                                { value: 'dark', label: __('Dark') },
                            ]}
                            value={appearance}
                            onValueChange={(next) =>
                                updateAppearance(next as Appearance)
                            }
                        />
                    </Field>
                </FormPanel>

                {/* Each choice is saved as it is made (docs/proposals/people-and-account.md); a typed value once it is left. */}
                <Form
                    {...PreferencesController.update.form()}
                    ref={form}
                    options={{ preserveScroll: true }}
                    onChange={(event) => {
                        if (!typed(event.target)) {
                            form.current?.submit();
                        }
                    }}
                    onFocus={(event) => {
                        typedAtFocus.current = typed(event.target)
                            ? event.target.value
                            : null;
                    }}
                    onBlur={(event) => {
                        if (
                            typed(event.target) &&
                            event.target.value !== typedAtFocus.current
                        ) {
                            form.current?.submit();
                        }
                    }}
                >
                    {({ errors }) =>
                        panels.map((panel) => (
                            <FormPanel key={panel.title} title={panel.title}>
                                {panel.fields.map((field) => (
                                    <SettingsField
                                        key={field.key}
                                        field={field}
                                        name={`preferences[${field.key}]`}
                                        value={values[field.key]}
                                        error={
                                            errors[`preferences.${field.key}`]
                                        }
                                    />
                                ))}
                            </FormPanel>
                        ))
                    }
                </Form>
            </SettingsPage>
        </>
    );
}

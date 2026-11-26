import { Form, Head, setLayoutProps } from '@inertiajs/react';
import type { FormComponentRef } from '@inertiajs/core';
import { useRef } from 'react';
import PreferencesController from '@/actions/App/Http/Admin/Account/PreferencesController';
import { SettingsField } from '@/components/fields/settings-field';
import { FormPanel } from '@/components/patterns/form-page';
import { SettingsPage } from '@/components/patterns/settings-page';
import { FieldInline } from '@/components/ui/field';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { Switch } from '@/components/ui/switch';
import { useAppearance, type Appearance } from '@/hooks/use-appearance';
import { __ } from '@/lib/i18n';
import type { FieldDefinition } from '@/types';

type Props = {
    /** Every declared preference but the lists, in the settings screen's field shape. */
    fields: FieldDefinition[];
    /** This person's values, defaults applied. */
    values: Record<string, unknown>;
};

/** Drawn on its own: the light or dark choice saves itself. */
const MODE = 'appearance.mode';

/** Drawn on its own, as a switch: checking spelling in the editor. */
const SPELLING = 'editor.spellcheck';

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
    const spelling = fields.find((field) => field.key === SPELLING);
    const others = fields.filter(
        (field) => field.key !== MODE && field.key !== SPELLING,
    );
    const panels = [
        ...PANELS.map((panel) => ({
            title: panel.title(),
            prefix: panel.prefix,
            fields: others.filter((field) =>
                field.key.startsWith(panel.prefix),
            ),
        })),
        {
            title: __('More'),
            prefix: '',
            fields: others.filter(
                (field) =>
                    !PANELS.some((panel) => field.key.startsWith(panel.prefix)),
            ),
        },
    ].filter(
        (panel) =>
            panel.fields.length > 0 ||
            (panel.prefix === 'editor.' && spelling !== undefined),
    );
    const title = __('Preferences');
    const description = __(
        'How the admin looks and behaves for you. Each choice saves as you make it.',
    );

    setLayoutProps({
        title,
        description,
    });

    return (
        <>
            <Head title={__('Preferences')} />

            <SettingsPage title={title} description={description} titleAs="div">
                <FormPanel title={__('Appearance')}>
                    <div className="grid gap-1">
                        <span
                            id="appearance-mode-label"
                            className="text-small text-ink-2 font-medium"
                        >
                            {__('Colors')}
                        </span>
                        <Segmented
                            aria-labelledby="appearance-mode-label"
                            aria-describedby="appearance-mode-help"
                            data-test="appearance-mode"
                            className="justify-self-start"
                            value={appearance}
                            onValueChange={(next) =>
                                updateAppearance(next as Appearance)
                            }
                        >
                            <SegmentedItem value="light">
                                {__('Light')}
                            </SegmentedItem>
                            <SegmentedItem value="dark">
                                {__('Dark')}
                            </SegmentedItem>
                            <SegmentedItem value="system">
                                {__('Match my computer')}
                            </SegmentedItem>
                        </Segmented>
                        <p
                            id="appearance-mode-help"
                            className="text-caption text-ink-3"
                        >
                            {__(
                                'Light, dark, or whatever your computer is set to. Only you see it.',
                            )}
                        </p>
                    </div>
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
                                {panel.prefix === 'editor.' &&
                                    spelling !== undefined && (
                                        <FieldInline
                                            htmlFor="pref-spelling"
                                            label={__(
                                                'Check my spelling in the editor',
                                            )}
                                            help={__(
                                                'Your browser underlines words it doesn’t know. Nothing is sent anywhere.',
                                            )}
                                            error={
                                                errors[
                                                    `preferences.${SPELLING}`
                                                ]
                                            }
                                        >
                                            <SpellingSwitch
                                                on={values[SPELLING] === true}
                                            />
                                        </FieldInline>
                                    )}
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

/**
 * The spelling switch: off sends "0" before it, on sends "1", as every
 * yes-or-no preference does, so the form saves it as it is turned.
 */
function SpellingSwitch({
    on,
    ...props
}: {
    on: boolean;
    'aria-describedby'?: string;
    'aria-invalid'?: boolean;
}) {
    return (
        <span className="contents">
            <input type="hidden" name={`preferences[${SPELLING}]`} value="0" />
            <Switch
                {...props}
                id="pref-spelling"
                name={`preferences[${SPELLING}]`}
                value="1"
                defaultChecked={on}
                data-test="spelling-switch"
            />
        </span>
    );
}

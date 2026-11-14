import { Form, Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import PreferencesController from '@/actions/App/Http/Admin/Account/PreferencesController';
import { SettingsField } from '@/components/fields/settings-field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { SelectField } from '@/components/ui/select-field';
import { useAppearance, type Appearance } from '@/hooks/use-appearance';
import { chooseLook } from '@/lib/preferences';
import type { LookPreference } from '@/lib/preferences';
import { cn } from '@/lib/utils';
import { edit as editPreferences } from '@/routes/preferences';
import type { FieldDefinition, Preferences as PreferenceValues } from '@/types';

type Props = {
    /** Every declared preference but the lists, in the settings screen's field shape. */
    fields: FieldDefinition[];
    /** This person's values, defaults applied. */
    values: Record<string, unknown>;
    /** What each look changes, one line each. */
    lookDescriptions: Record<string, string>;
};

/** Drawn on their own: the light or dark switch saves itself, and the look is cards. */
const MODE = 'appearance.mode';
const LOOK = 'appearance.look';
/** Drawn beside the look cards, since it applies under any of them. */
const CONTRAST = 'appearance.high_contrast';

/**
 * The person's own preferences (I-110), drawn from the registry with the
 * settings screen's components. They change nothing for anyone else.
 */
export default function Preferences({
    fields,
    values,
    lookDescriptions,
}: Props) {
    const { appearance, updateAppearance } = useAppearance();
    const { preferences } = usePage().props;
    const [refused, setRefused] = useState(false);
    const look = fields.find((field) => field.key === LOOK);
    const contrast = fields.find((field) => field.key === CONTRAST);
    const others = fields.filter(
        (field) => ![MODE, LOOK, CONTRAST].includes(field.key),
    );

    /** Applies a look at once and saves it; says so only when it was put back (I-198). */
    const choose = <K extends LookPreference>(
        key: K,
        value: PreferenceValues[K],
    ) => {
        setRefused(false);
        void chooseLook(key, value, preferences[key]).then((outcome) =>
            setRefused(outcome === 'put back'),
        );
    };

    return (
        <>
            <Head title="Preferences" />

            <div className="space-y-6">
                <div className="grid gap-2">
                    <Label htmlFor="appearance-mode">Appearance</Label>
                    <SelectField
                        id="appearance-mode"
                        aria-describedby="appearance-mode-hint"
                        options={[
                            { value: 'system', label: 'Match the system' },
                            { value: 'light', label: 'Light' },
                            { value: 'dark', label: 'Dark' },
                        ]}
                        value={appearance}
                        onValueChange={(next) =>
                            updateAppearance(next as Appearance)
                        }
                    />
                    <p
                        id="appearance-mode-hint"
                        className="text-muted-foreground text-sm"
                    >
                        {'Saved as you choose.'}
                    </p>
                </div>

                {look !== undefined && (
                    <fieldset
                        className="grid gap-2"
                        aria-describedby="appearance-look-hint"
                    >
                        <legend className="mb-2 text-sm font-medium">
                            {look.label}
                        </legend>
                        {/* Later: a small preview of each look on its card. That needs preview tokens
                            in app.css (each look's colours scoped to its card), which nothing has yet. */}
                        <div className="grid gap-2 sm:grid-cols-3">
                            {Object.entries(look.options).map(
                                ([value, label]) => (
                                    <label
                                        key={value}
                                        className={cn(
                                            'border-border has-[:checked]:border-primary has-[:checked]:bg-selected grid cursor-pointer gap-1 rounded-md border p-3 text-sm',
                                            'has-[:focus-visible]:ring-ring has-[:focus-visible]:ring-2',
                                        )}
                                    >
                                        <span className="flex items-center gap-2 font-medium">
                                            <input
                                                type="radio"
                                                name="appearance-look"
                                                value={value}
                                                checked={
                                                    preferences[LOOK] === value
                                                }
                                                onChange={() =>
                                                    choose(LOOK, value)
                                                }
                                            />
                                            {label}
                                        </span>
                                        <span className="text-muted-foreground text-xs">
                                            {lookDescriptions[value] ?? ''}
                                        </span>
                                    </label>
                                ),
                            )}
                        </div>
                        <p
                            id="appearance-look-hint"
                            className="text-muted-foreground text-sm"
                        >
                            {`${look.description} Saved as you choose.`}
                        </p>
                    </fieldset>
                )}

                {contrast !== undefined && (
                    <div className="grid gap-1">
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="appearance-high-contrast"
                                checked={preferences[CONTRAST] === true}
                                aria-describedby="appearance-high-contrast-hint"
                                onCheckedChange={(checked) =>
                                    choose(CONTRAST, checked === true)
                                }
                            />
                            <Label htmlFor="appearance-high-contrast">
                                {contrast.label}
                            </Label>
                        </div>
                        <p
                            id="appearance-high-contrast-hint"
                            className="text-muted-foreground text-sm"
                        >
                            {`${contrast.description} Saved as you choose.`}
                        </p>
                    </div>
                )}

                {/* Stays in the page, empty, so a refusal is announced reliably. */}
                <p role="status" className="text-destructive text-sm">
                    {refused
                        ? 'Your choice couldn’t be saved, so the admin is back as it was. Try again in a moment.'
                        : ''}
                </p>

                <Form
                    {...PreferencesController.update.form()}
                    options={{ preserveScroll: true }}
                    className="border-border space-y-6 border-t pt-6"
                >
                    {({ processing, errors }) => (
                        <>
                            {others.map((field) => (
                                <SettingsField
                                    key={field.key}
                                    field={field}
                                    name={`preferences[${field.key}]`}
                                    value={values[field.key]}
                                    error={errors[`preferences.${field.key}`]}
                                />
                            ))}

                            <Button disabled={processing}>
                                Save preferences
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

Preferences.layout = {
    breadcrumbs: [
        {
            title: 'Preferences',
            href: editPreferences(),
        },
    ],
};

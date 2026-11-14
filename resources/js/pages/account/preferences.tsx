import { Form, Head } from '@inertiajs/react';
import PreferencesController from '@/actions/App/Http/Admin/Account/PreferencesController';
import { SettingsField } from '@/components/fields/settings-field';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { SelectField } from '@/components/ui/select-field';
import { useAppearance, type Appearance } from '@/hooks/use-appearance';
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

/**
 * The person's own preferences (I-110), drawn from the registry with the
 * settings screen's components. They change nothing for anyone else.
 */
export default function Preferences({ fields, values }: Props) {
    const { appearance, updateAppearance } = useAppearance();
    const others = fields.filter((field) => field.key !== MODE);

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
                    <p id="appearance-mode-hint" className="text-ink-2 text-sm">
                        {'Saved as you choose.'}
                    </p>
                </div>

                <Form
                    {...PreferencesController.update.form()}
                    options={{ preserveScroll: true }}
                    className="border-line space-y-6 border-t pt-6"
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

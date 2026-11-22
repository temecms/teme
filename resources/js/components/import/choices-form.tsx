import type { FormComponentRef } from '@inertiajs/core';
import { Form } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ImportRunController from '@/actions/App/Http/Admin/Tools/Import/ImportRunController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldInline } from '@/components/ui/field';
import { SelectField } from '@/components/ui/select-field';
import { __, __n } from '@/lib/i18n';
import type {
    ChoicesForm as ChoicesFormView,
    RememberedChoices,
} from '@/types/import';

/** A group of choices: its legend, a line on what it is for, and its error. */
const LEGEND = 'text-body text-ink font-semibold';
const GROUP = 'grid gap-4';

/**
 * The choices an import needs, with their defaults, and Start (I-227). The
 * choices kept across the password's confirmation start the form, so nothing
 * is chosen twice.
 */
export function ChoicesForm({
    siteId,
    form,
    remembered,
    resume = false,
}: {
    siteId: string;
    form: ChoicesFormView;
    remembered: RememberedChoices | null;
    /** Send the form once at once: a start the password's confirmation interrupted. */
    resume?: boolean;
}) {
    // What starts each choice: the choices kept across the password's
    // confirmation, else the screen's defaults.
    const location = (key: string, fallback: string): string =>
        remembered === null ? fallback : (remembered.menu_locations[key] ?? '');
    const template = (key: string, fallback: string): string =>
        remembered === null ? fallback : (remembered.templates[key] ?? '');
    const role = (key: string, fallback: string): string =>
        remembered?.roles[key] ?? fallback;
    const [ticked, setTicked] = useState<string[]>(
        remembered?.outside_images ?? [],
    );

    const formRef = useRef<FormComponentRef>(null);

    // Carries the start on once the person is back from confirming their password.
    useEffect(() => {
        if (resume) {
            formRef.current?.submit();
        }
    }, [resume]);

    return (
        <Form
            ref={formRef}
            {...ImportRunController.start.form({ source: siteId })}
            className="grid gap-6"
        >
            {({ processing, errors }) => (
                <>
                    {form.menuLocations.length > 0 && (
                        <fieldset
                            className={GROUP}
                            aria-describedby="import-menu-locations-error"
                        >
                            <legend className={LEGEND}>
                                {__('Menu locations')}
                            </legend>
                            <p className="text-small text-ink-2">
                                {__(
                                    'Where each menu goes in this site’s theme.',
                                )}
                            </p>
                            {form.slots.length === 0 && (
                                <p className="text-body">
                                    {__(
                                        'This theme has no places for menus. Menus come across, and you can place them later.',
                                    )}
                                </p>
                            )}
                            {form.slots.length > 0 &&
                                form.menuLocations.map((row) => (
                                    <Field
                                        key={row.key}
                                        htmlFor={`import-location-${row.key}`}
                                        label={row.key}
                                        error={
                                            errors[`menu_locations.${row.key}`]
                                        }
                                    >
                                        <SelectField
                                            id={`import-location-${row.key}`}
                                            name={`menu_locations[${row.key}]`}
                                            options={form.slots}
                                            emptyLabel={__('Don’t place it')}
                                            defaultValue={location(
                                                row.key,
                                                row.value,
                                            )}
                                        />
                                    </Field>
                                ))}
                            <InputError
                                id="import-menu-locations-error"
                                message={errors.menu_locations}
                            />
                        </fieldset>
                    )}

                    {form.templates.length > 0 && (
                        <fieldset
                            className={GROUP}
                            aria-describedby="import-templates-error"
                        >
                            <legend className={LEGEND}>
                                {__('Page templates')}
                            </legend>
                            <p className="text-small text-ink-2">
                                {__(
                                    'The template each WordPress page template becomes.',
                                )}
                            </p>
                            {form.templates.map((row) => (
                                <Field
                                    key={row.key}
                                    htmlFor={`import-template-${row.key}`}
                                    label={__(':template (:pages)', {
                                        template: row.key,
                                        pages: __n(
                                            ':count page|:count pages',
                                            row.count,
                                            {
                                                count: row.count.toLocaleString(),
                                            },
                                        ),
                                    })}
                                    error={errors[`templates.${row.key}`]}
                                >
                                    <SelectField
                                        id={`import-template-${row.key}`}
                                        name={`templates[${row.key}]`}
                                        options={form.themeTemplates}
                                        emptyLabel={__('The theme’s default')}
                                        defaultValue={template(
                                            row.key,
                                            row.value,
                                        )}
                                    />
                                </Field>
                            ))}
                            <InputError
                                id="import-templates-error"
                                message={errors.templates}
                            />
                        </fieldset>
                    )}

                    {form.roles.length > 0 && (
                        <fieldset
                            className={GROUP}
                            aria-describedby="import-roles-error"
                        >
                            <legend className={LEGEND}>
                                {__('Custom roles')}
                            </legend>
                            <p className="text-small text-ink-2">
                                {__(
                                    'WordPress roles Teme doesn’t have. People with them get the role you choose, or stay behind.',
                                )}
                            </p>
                            {form.roles.map((row) => (
                                <Field
                                    key={row.key}
                                    htmlFor={`import-role-${row.key}`}
                                    label={row.key}
                                    error={errors[`roles.${row.key}`]}
                                >
                                    <SelectField
                                        id={`import-role-${row.key}`}
                                        name={`roles[${row.key}]`}
                                        options={form.roleOptions}
                                        defaultValue={role(row.key, row.value)}
                                    />
                                </Field>
                            ))}
                            <InputError
                                id="import-roles-error"
                                message={errors.roles}
                            />
                        </fieldset>
                    )}

                    <Field
                        htmlFor="import-owner"
                        label={__('Items whose author is gone')}
                        help={__(
                            'Posts and pages whose WordPress author was deleted belong to this person.',
                        )}
                        error={errors.owner}
                    >
                        <SelectField
                            id="import-owner"
                            name="owner"
                            options={form.people}
                            defaultValue={remembered?.owner ?? form.owner}
                        />
                    </Field>

                    {form.outsideHosts.length > 0 && (
                        <fieldset
                            className={GROUP}
                            aria-describedby="import-outside-images-error"
                        >
                            <legend className={LEGEND}>
                                {__('Images from other sites')}
                            </legend>
                            <p className="text-small text-ink-2">
                                {__(
                                    'Posts show images kept on these sites. Tick a site to bring its images into the media library. The rest become links, with each image’s description as the words.',
                                )}
                            </p>
                            {form.outsideHosts.map((row, index) => (
                                <FieldInline
                                    key={row.host}
                                    htmlFor={`import-host-${index}`}
                                    label={__(':host (:images)', {
                                        host: row.host,
                                        images: __n(
                                            ':count image|:count images',
                                            row.count,
                                            {
                                                count: row.count.toLocaleString(),
                                            },
                                        ),
                                    })}
                                >
                                    <Checkbox
                                        id={`import-host-${index}`}
                                        checked={ticked.includes(row.host)}
                                        onCheckedChange={(checked) =>
                                            setTicked((hosts) =>
                                                checked === true
                                                    ? [...hosts, row.host]
                                                    : hosts.filter(
                                                          (host) =>
                                                              host !== row.host,
                                                      ),
                                            )
                                        }
                                    />
                                </FieldInline>
                            ))}
                            {ticked.map((host) => (
                                <input
                                    key={host}
                                    type="hidden"
                                    name="outside_images[]"
                                    value={host}
                                />
                            ))}
                            <InputError
                                id="import-outside-images-error"
                                message={errors.outside_images}
                            />
                        </fieldset>
                    )}

                    <Field
                        htmlFor="import-pause"
                        label={__('Pause between requests')}
                        help={__(
                            'The old site stays live while Teme reads it. A pause keeps it quick for its readers.',
                        )}
                        error={errors.pause}
                    >
                        <SelectField
                            id="import-pause"
                            name="pause"
                            options={form.pauses}
                            defaultValue={String(
                                remembered?.pause ?? form.pause,
                            )}
                        />
                    </Field>

                    <div className="grid justify-items-start gap-2">
                        <InputError
                            id="import-choices-error"
                            message={errors.choices}
                        />
                        <Button
                            type="submit"
                            data-test="import-start"
                            aria-describedby="import-choices-error"
                            disabled={processing}
                        >
                            {__('Start the import')}
                        </Button>
                        <p className="text-small text-ink-2">
                            {__(
                                'Teme asks for your password first. The import runs in the background: you can leave this page, and the result reaches your inbox.',
                            )}
                        </p>
                    </div>
                </>
            )}
        </Form>
    );
}

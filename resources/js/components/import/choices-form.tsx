import { Form } from '@inertiajs/react';
import { useState } from 'react';
import ImportRunController from '@/actions/App/Http/Admin/Tools/Import/ImportRunController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { SelectField } from '@/components/ui/select-field';
import type {
    ChoicesForm as ChoicesFormView,
    RememberedChoices,
} from '@/types/import';

/**
 * The choices an import needs, with their defaults, and Start (I-227). The
 * choices kept across the password's confirmation start the form, so nothing
 * is chosen twice.
 */
export function ChoicesForm({
    siteId,
    form,
    remembered,
}: {
    siteId: string;
    form: ChoicesFormView;
    remembered: RememberedChoices | null;
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

    return (
        <Form
            {...ImportRunController.start.form({ source: siteId })}
            className="max-w-[640px] space-y-6"
        >
            {({ processing, errors }) => (
                <>
                    {form.menuLocations.length > 0 && (
                        <fieldset
                            className="space-y-3"
                            aria-describedby="import-menu-locations-error"
                        >
                            <legend className="text-sm font-medium">
                                Menu locations
                            </legend>
                            <p className="text-ink-2 text-[13px]">
                                Where each menu goes in this site’s theme.
                            </p>
                            {form.slots.length === 0 && (
                                <p className="text-[13px]">
                                    This theme has no places for menus. Menus
                                    come across, and you can place them later.
                                </p>
                            )}
                            {form.slots.length > 0 &&
                                form.menuLocations.map((row) => (
                                    <div key={row.key} className="grid gap-1.5">
                                        <Label
                                            htmlFor={`import-location-${row.key}`}
                                            className="font-mono text-[13px]"
                                        >
                                            {row.key}
                                        </Label>
                                        <SelectField
                                            id={`import-location-${row.key}`}
                                            name={`menu_locations[${row.key}]`}
                                            options={form.slots}
                                            emptyLabel="Don’t place it"
                                            defaultValue={location(
                                                row.key,
                                                row.value,
                                            )}
                                            aria-describedby={`import-location-${row.key}-error`}
                                        />
                                        <InputError
                                            id={`import-location-${row.key}-error`}
                                            message={
                                                errors[
                                                    `menu_locations.${row.key}`
                                                ]
                                            }
                                        />
                                    </div>
                                ))}
                            <InputError
                                id="import-menu-locations-error"
                                message={errors.menu_locations}
                            />
                        </fieldset>
                    )}

                    {form.templates.length > 0 && (
                        <fieldset
                            className="space-y-3"
                            aria-describedby="import-templates-error"
                        >
                            <legend className="text-sm font-medium">
                                Page templates
                            </legend>
                            <p className="text-ink-2 text-[13px]">
                                The template each WordPress page template
                                becomes.
                            </p>
                            {form.templates.map((row) => (
                                <div key={row.key} className="grid gap-1.5">
                                    <Label
                                        htmlFor={`import-template-${row.key}`}
                                        className="text-[13px]"
                                    >
                                        <span className="font-mono">
                                            {row.key}
                                        </span>{' '}
                                        (
                                        {row.count === 1
                                            ? '1 page'
                                            : `${row.count.toLocaleString()} pages`}
                                        )
                                    </Label>
                                    <SelectField
                                        id={`import-template-${row.key}`}
                                        name={`templates[${row.key}]`}
                                        options={form.themeTemplates}
                                        emptyLabel="The theme’s default"
                                        defaultValue={template(
                                            row.key,
                                            row.value,
                                        )}
                                        aria-describedby={`import-template-${row.key}-error`}
                                    />
                                    <InputError
                                        id={`import-template-${row.key}-error`}
                                        message={errors[`templates.${row.key}`]}
                                    />
                                </div>
                            ))}
                            <InputError
                                id="import-templates-error"
                                message={errors.templates}
                            />
                        </fieldset>
                    )}

                    {form.roles.length > 0 && (
                        <fieldset
                            className="space-y-3"
                            aria-describedby="import-roles-error"
                        >
                            <legend className="text-sm font-medium">
                                Custom roles
                            </legend>
                            <p className="text-ink-2 text-[13px]">
                                WordPress roles Teme doesn’t have. People with
                                them get the role you choose, or stay behind.
                            </p>
                            {form.roles.map((row) => (
                                <div key={row.key} className="grid gap-1.5">
                                    <Label
                                        htmlFor={`import-role-${row.key}`}
                                        className="font-mono text-[13px]"
                                    >
                                        {row.key}
                                    </Label>
                                    <SelectField
                                        id={`import-role-${row.key}`}
                                        name={`roles[${row.key}]`}
                                        options={form.roleOptions}
                                        defaultValue={role(row.key, row.value)}
                                        aria-describedby={`import-role-${row.key}-error`}
                                    />
                                    <InputError
                                        id={`import-role-${row.key}-error`}
                                        message={errors[`roles.${row.key}`]}
                                    />
                                </div>
                            ))}
                            <InputError
                                id="import-roles-error"
                                message={errors.roles}
                            />
                        </fieldset>
                    )}

                    <div className="grid gap-1.5">
                        <Label htmlFor="import-owner">
                            Items whose author is gone
                        </Label>
                        <SelectField
                            id="import-owner"
                            name="owner"
                            options={form.people}
                            defaultValue={remembered?.owner ?? form.owner}
                            aria-describedby="import-owner-hint import-owner-error"
                        />
                        <p
                            id="import-owner-hint"
                            className="text-ink-2 text-[13px]"
                        >
                            Posts and pages whose WordPress author was deleted
                            belong to this person.
                        </p>
                        <InputError
                            id="import-owner-error"
                            message={errors.owner}
                        />
                    </div>

                    {form.outsideHosts.length > 0 && (
                        <fieldset
                            className="space-y-3"
                            aria-describedby="import-outside-images-error"
                        >
                            <legend className="text-sm font-medium">
                                Images from other sites
                            </legend>
                            <p className="text-ink-2 text-[13px]">
                                Posts show images kept on these sites. Tick a
                                site to bring its images into the media library.
                                The rest become links, with each image’s
                                description as the words.
                            </p>
                            {form.outsideHosts.map((row, index) => (
                                <div
                                    key={row.host}
                                    className="flex items-center gap-2"
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
                                    <Label
                                        htmlFor={`import-host-${index}`}
                                        className="text-[13px]"
                                    >
                                        {row.host} (
                                        {row.count === 1
                                            ? '1 image'
                                            : `${row.count.toLocaleString()} images`}
                                        )
                                    </Label>
                                    {ticked.includes(row.host) && (
                                        <input
                                            type="hidden"
                                            name="outside_images[]"
                                            value={row.host}
                                        />
                                    )}
                                </div>
                            ))}
                            <InputError
                                id="import-outside-images-error"
                                message={errors.outside_images}
                            />
                        </fieldset>
                    )}

                    <div className="grid gap-1.5">
                        <Label htmlFor="import-pause">
                            Pause between requests
                        </Label>
                        <SelectField
                            id="import-pause"
                            name="pause"
                            options={form.pauses}
                            defaultValue={String(
                                remembered?.pause ?? form.pause,
                            )}
                            aria-describedby="import-pause-hint import-pause-error"
                        />
                        <p
                            id="import-pause-hint"
                            className="text-ink-2 text-[13px]"
                        >
                            The old site stays live while Teme reads it. A pause
                            keeps it quick for its readers.
                        </p>
                        <InputError
                            id="import-pause-error"
                            message={errors.pause}
                        />
                    </div>

                    <div className="space-y-2">
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
                            Start the import
                        </Button>
                        <p className="text-ink-2 text-[13px]">
                            Teme asks for your password first. The import runs
                            in the background: you can leave this page, and the
                            result reaches your inbox.
                        </p>
                    </div>
                </>
            )}
        </Form>
    );
}

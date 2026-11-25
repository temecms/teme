import type { FormComponentRef } from '@inertiajs/core';
import { Form } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ImportRunController from '@/actions/App/Http/Admin/Tools/Import/ImportRunController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldInline } from '@/components/ui/field';
import { RadioGroup, RadioGroupChoice } from '@/components/ui/radio-group';
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
    const role = (key: string, fallback: string): string =>
        remembered?.roles[key] ?? fallback;
    const [ticked, setTicked] = useState<string[]>(
        remembered?.outside_images ?? [],
    );

    const [media, setMedia] = useState<string>(remembered?.media ?? form.media);

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

                    <fieldset
                        className={GROUP}
                        aria-describedby="import-media-error"
                    >
                        <legend className={LEGEND}>{__('Media files')}</legend>
                        <RadioGroup
                            name="media"
                            value={media}
                            onValueChange={setMedia}
                        >
                            <RadioGroupChoice
                                id="import-media-download"
                                value="download"
                                label={__('Download them with the articles')}
                            />
                            <RadioGroupChoice
                                id="import-media-later"
                                value="later"
                                label={__(
                                    'Bring the articles first, then the pictures',
                                )}
                                description={__(
                                    'Posts show their pictures from the old site until each is copied here',
                                )}
                            />
                        </RadioGroup>
                        <InputError
                            id="import-media-error"
                            message={errors.media}
                        />
                    </fieldset>

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

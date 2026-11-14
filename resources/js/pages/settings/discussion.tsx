import { Form, Head } from '@inertiajs/react';
import { Info, Lock } from 'lucide-react';
import DiscussionSettingsController from '@/actions/App/Http/Admin/Settings/DiscussionSettingsController';
import { SettingsField } from '@/components/fields/settings-field';
import { SettingsPanel } from '@/components/fields/settings-panel';
import { SettingsSaveBar } from '@/components/fields/settings-save-bar';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import type { DiscussionSettingsPage } from '@/types';

/**
 * Settings › Discussion (E1, E2; 1.8d's D2 to D4; v9's settingsForm): the
 * comments switch first, with its own Save, read-only without
 * settings.modules.manage and alone while comments are off; then the
 * Discussion settings in v9's fieldsets, saved together.
 */
export default function DiscussionSettings({
    description,
    comments,
    fieldsets,
    values,
    version,
}: DiscussionSettingsPage) {
    return (
        <>
            <Head title="Discussion settings" />

            <div className="grid max-w-[960px] gap-6">
                <Heading
                    level={1}
                    title="Discussion"
                    description={description}
                />

                <Form
                    id="comments-switch"
                    {...DiscussionSettingsController.switchComments.form()}
                    options={{ preserveScroll: true }}
                >
                    {({ processing, errors }) => (
                        <SettingsPanel
                            title="Comments on this site"
                            description="Comments are an optional part of Teme, switched on or off for the whole site."
                            footer={
                                comments.canSwitch ? (
                                    <>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Save the comments switch
                                        </Button>
                                        <span className="text-ink-2 text-xs">
                                            Saves this switch only.
                                        </span>
                                    </>
                                ) : (
                                    <p className="text-ink-2 flex items-center gap-2 text-sm">
                                        <Lock
                                            aria-hidden="true"
                                            className="size-4"
                                        />
                                        Only administrators can switch comments
                                        on or off.
                                    </p>
                                )
                            }
                        >
                            <input
                                type="hidden"
                                name="expected_version"
                                value={comments.version}
                            />
                            <InputError message={errors.expected_version} />

                            <SettingsField
                                field={comments.field}
                                value={comments.value}
                                disabled={!comments.canSwitch}
                                error={errors['values.comments']}
                            />
                        </SettingsPanel>
                    )}
                </Form>

                {comments.value === 'off' && (
                    <p className="text-sm">
                        Comments are off, so the settings for them are hidden.
                        They’re kept, unchanged, for when comments are switched
                        back on.
                    </p>
                )}

                {fieldsets.length > 0 && (
                    <Form
                        {...DiscussionSettingsController.update.form()}
                        options={{ preserveScroll: true }}
                        className="grid gap-6"
                    >
                        {({ processing, errors }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="expected_version"
                                    value={version}
                                />
                                <InputError message={errors.expected_version} />
                                <InputError message={errors.values} />

                                {fieldsets.map((fieldset) => (
                                    <SettingsPanel
                                        key={fieldset.legend}
                                        title={fieldset.legend}
                                        description={fieldset.description}
                                    >
                                        {fieldset.info !== null && (
                                            <div className="grid gap-1 text-sm">
                                                <p className="font-medium">
                                                    {fieldset.info.label}
                                                </p>
                                                <p>{fieldset.info.value}</p>
                                                <p className="text-ink-2 flex items-start gap-2">
                                                    <Info
                                                        aria-hidden="true"
                                                        className="mt-0.5 size-4 shrink-0"
                                                    />
                                                    {fieldset.info.hint}
                                                </p>
                                            </div>
                                        )}

                                        {fieldset.fields.map((field) => (
                                            <SettingsField
                                                key={field.key}
                                                field={field}
                                                value={values[field.key]}
                                                error={
                                                    errors[
                                                        `values.${field.key}`
                                                    ]
                                                }
                                            />
                                        ))}
                                    </SettingsPanel>
                                ))}

                                <SettingsSaveBar
                                    label="Save discussion settings"
                                    note="Saves every section below the comments switch."
                                    processing={processing}
                                />
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}

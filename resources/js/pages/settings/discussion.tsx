import { Head, router, setLayoutProps } from '@inertiajs/react';
import { Info, Lock } from 'lucide-react';
import { useState } from 'react';
import DiscussionSettingsController from '@/actions/App/Http/Admin/Settings/DiscussionSettingsController';
import { SettingsField } from '@/components/fields/settings-field';
import { SettingsForm } from '@/components/fields/settings-form';
import { SettingsPanel } from '@/components/fields/settings-panel';
import InputError from '@/components/input-error';
import { SettingsPage } from '@/components/patterns/settings-page';
import { Label } from '@/components/ui/label';
import { Notice } from '@/components/ui/notice';
import { Switch } from '@/components/ui/switch';
import { __ } from '@/lib/i18n';
import { index as settingsIndex } from '@/routes/settings';
import type { DiscussionSettingsPage } from '@/types';

/**
 * Settings › Discussion: the comments switch first, acting at once
 * (docs/architecture/design-system.md, 4.10), read-only without
 * settings.modules.manage, held while the sections below have unsaved
 * changes, and alone while comments are off; then the Discussion settings
 * in their sections, saved together by the page's one save bar.
 */
export default function DiscussionSettings({
    description,
    comments,
    fieldsets,
    values,
    version,
}: DiscussionSettingsPage) {
    const [switching, setSwitching] = useState(false);
    const [unsaved, setUnsaved] = useState(false);
    const [switchError, setSwitchError] = useState<string | undefined>();

    setLayoutProps({
        breadcrumbs: [
            { title: __('Settings'), href: settingsIndex() },
            {
                title: __('Discussion'),
                href: DiscussionSettingsController.show.url(),
            },
        ],
    });

    const switchComments = (next: boolean) => {
        router.put(
            DiscussionSettingsController.switchComments.url(),
            {
                values: { comments: next ? 'on' : 'off' },
                expected_version: comments.version,
            },
            {
                preserveScroll: true,
                onStart: () => setSwitching(true),
                onFinish: () => setSwitching(false),
                onError: (errors) => setSwitchError(Object.values(errors)[0]),
                onSuccess: () => setSwitchError(undefined),
            },
        );
    };

    return (
        <>
            <Head title={__('Discussion settings')} />

            <SettingsPage title={__('Discussion')} description={description}>
                <SettingsPanel
                    as="section"
                    id="comments-switch"
                    title={__('Comments on this site')}
                    description={__(
                        'The page stays when comments are off, because it holds the switch.',
                    )}
                >
                    <div className="grid gap-1">
                        <div className="flex items-center gap-2">
                            <Switch
                                id="field-comments"
                                checked={comments.value === 'on'}
                                disabled={
                                    !comments.canSwitch || switching || unsaved
                                }
                                onCheckedChange={switchComments}
                                aria-describedby="field-comments-hint field-comments-error"
                            />
                            <Label
                                htmlFor="field-comments"
                                className="text-body text-ink"
                            >
                                {comments.field.label}
                            </Label>
                        </div>
                        <p
                            id="field-comments-hint"
                            className="text-caption text-ink-3 flex items-center gap-2"
                        >
                            {!comments.canSwitch ? (
                                <>
                                    <Lock
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                    {__(
                                        'Only administrators can switch comments on or off.',
                                    )}
                                </>
                            ) : unsaved ? (
                                __('Save or discard the changes below first.')
                            ) : (
                                comments.field.description
                            )}
                        </p>
                        <InputError
                            id="field-comments-error"
                            className="text-small"
                            message={switchError}
                        />
                    </div>
                </SettingsPanel>

                {comments.value === 'off' && (
                    <Notice tone="info">
                        {__(
                            'Comments are off, so the settings for them are hidden. They’re kept, unchanged, for when comments are switched back on.',
                        )}
                    </Notice>
                )}

                {fieldsets.length > 0 && (
                    <SettingsForm
                        action={DiscussionSettingsController.update.form()}
                        what={__(':page settings', {
                            page: __('Discussion'),
                        })}
                        onDirtyChange={setUnsaved}
                    >
                        {({ errors }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="expected_version"
                                    value={version}
                                />
                                <InputError
                                    id="expected-version-error"
                                    message={errors.expected_version}
                                />
                                <InputError
                                    id="values-error"
                                    message={errors.values}
                                />

                                {fieldsets.map((fieldset) => (
                                    <SettingsPanel
                                        key={fieldset.legend}
                                        title={fieldset.legend}
                                        description={
                                            fieldset.description !== ''
                                                ? fieldset.description
                                                : undefined
                                        }
                                    >
                                        {fieldset.info !== null && (
                                            <div className="grid gap-1">
                                                <p className="text-small text-ink-2 font-medium">
                                                    {fieldset.info.label}
                                                </p>
                                                <p className="text-body">
                                                    {fieldset.info.value}
                                                </p>
                                                <p className="text-caption text-ink-3 flex items-start gap-2">
                                                    <span className="flex h-lh shrink-0 items-center">
                                                        <Info
                                                            aria-hidden="true"
                                                            className="size-4"
                                                        />
                                                    </span>
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
                            </>
                        )}
                    </SettingsForm>
                )}
            </SettingsPage>
        </>
    );
}

import { Form } from '@inertiajs/react';
import ImportSourceController from '@/actions/App/Http/Admin/Tools/Import/ImportSourceController';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { __ } from '@/lib/i18n';
import { QuietNote } from '@/components/tools/quiet-note';

/**
 * Connecting a WordPress site, or connecting a forgotten one again (I-227).
 * The form never resets on an error, so what was typed stays; the
 * application password never goes through the session (I-1708).
 */
export function ConnectForm({
    address,
    userName,
    companion,
}: {
    address: string | null;
    userName: string | null;
    companion?: { version: string; releases: string | null };
}) {
    const again = address !== null;

    return (
        <Panel aria-labelledby="import-connect-heading">
            <PanelHeader>
                <h2
                    id="import-connect-heading"
                    className="text-lead font-semibold"
                >
                    {again
                        ? __('Connect again')
                        : __('Connect a WordPress site')}
                </h2>
            </PanelHeader>
            <PanelBody>
                <div className="grid max-w-(--form-max) gap-5">
                    {again ? (
                        <p className="text-body">
                            {__(
                                'To import from this site again, make a new application password in WordPress and enter it here.',
                            )}
                        </p>
                    ) : (
                        <QuietNote testId="import-first-steps">
                            <p className="text-ink font-semibold">
                                {__('First, on the WordPress site:')}
                            </p>
                            <ol className="grid list-decimal gap-2 ps-5">
                                <li>
                                    {__(
                                        'Install and activate the Teme Companion plugin',
                                    )}
                                    {companion?.releases != null && (
                                        <>
                                            {' '}
                                            <a
                                                href={companion.releases}
                                                className="text-accent-ink font-medium underline-offset-4 hover:underline"
                                            >
                                                {__('Download Teme Companion')}
                                            </a>
                                        </>
                                    )}
                                    .
                                </li>
                                <li>
                                    {__(
                                        'Open Tools › Teme site report and run it. Teme reads the report to show what comes across.',
                                    )}
                                </li>
                                <li>
                                    {__(
                                        'Open Users › Profile and add an application password named Teme. Copy it: WordPress shows it once.',
                                    )}
                                </li>
                            </ol>
                        </QuietNote>
                    )}

                    <Form
                        {...ImportSourceController.connect.form()}
                        className="grid gap-5"
                    >
                        {({ processing, errors }) => (
                            <>
                                {again ? (
                                    <input
                                        type="hidden"
                                        name="address"
                                        value={address}
                                    />
                                ) : (
                                    <Field
                                        htmlFor="import-address"
                                        label={__('Site address')}
                                        help={__(
                                            'The address readers use, such as https://example.com.',
                                        )}
                                        error={errors.address}
                                    >
                                        <Input
                                            id="import-address"
                                            name="address"
                                            type="url"
                                            inputMode="url"
                                            autoComplete="url"
                                            placeholder={__(
                                                'https://example.com',
                                            )}
                                        />
                                    </Field>
                                )}

                                <div className="grid gap-5 sm:grid-cols-2">
                                    <Field
                                        htmlFor="import-user-name"
                                        label={__('WordPress user name')}
                                        help={__(
                                            'The account that made the application password.',
                                        )}
                                        error={errors.user_name}
                                    >
                                        <Input
                                            id="import-user-name"
                                            name="user_name"
                                            autoComplete="username"
                                            defaultValue={userName ?? undefined}
                                        />
                                    </Field>

                                    <Field
                                        htmlFor="import-application-password"
                                        label={__('Application password')}
                                        help={__(
                                            'Teme keeps it encrypted until you choose Forget this site, and never shows it again.',
                                        )}
                                        error={errors.application_password}
                                    >
                                        <PasswordInput
                                            id="import-application-password"
                                            name="application_password"
                                            autoComplete="off"
                                        />
                                    </Field>
                                </div>

                                <div className="flex flex-wrap items-center gap-3">
                                    <Button
                                        type="submit"
                                        data-test="import-connect"
                                        disabled={processing}
                                    >
                                        {processing
                                            ? __('Connecting…')
                                            : __('Connect')}
                                    </Button>
                                    <p className="text-small text-ink-2">
                                        {__(
                                            'Connecting can take up to two minutes if the site is slow to answer.',
                                        )}
                                    </p>
                                </div>
                            </>
                        )}
                    </Form>
                </div>
            </PanelBody>
        </Panel>
    );
}

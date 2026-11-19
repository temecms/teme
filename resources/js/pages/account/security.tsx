import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { useRef } from 'react';
import SecurityController from '@/actions/App/Http/Admin/Account/SecurityController';
import { TwoFactorStatus } from '@/components/account/two-factor-status';
import { FormPanel } from '@/components/patterns/form-page';
import { SettingsPage } from '@/components/patterns/settings-page';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { __ } from '@/lib/i18n';
import { passwordMinimum } from '@/lib/password';
import { edit } from '@/routes/security';

type Props = {
    passwordRules: string;
    /** Whether the site lets people turn two-factor sign-in on. */
    canManageTwoFactor: boolean;
    twoFactorEnabled: boolean;
};

/**
 * Your account › Security: the password you sign in with, updated by its
 * own button, and whether two-factor sign-in is on.
 */
export default function Security({
    passwordRules,
    canManageTwoFactor,
    twoFactorEnabled,
}: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);
    const minimum = passwordMinimum(passwordRules);
    const title = __('Security');
    const description = __('Your password and two-factor sign-in.');

    setLayoutProps({
        breadcrumbs: [{ title, href: edit() }],
        title,
        description,
    });

    return (
        <>
            <Head title={title} />

            <SettingsPage title={title} description={description} titleAs="div">
                <Form
                    {...SecurityController.update.form()}
                    options={{ preserveScroll: true }}
                    resetOnError={[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]}
                    resetOnSuccess
                    onError={(errors) => {
                        if (errors.password) {
                            passwordInput.current?.focus();
                        }

                        if (errors.current_password) {
                            currentPasswordInput.current?.focus();
                        }
                    }}
                >
                    {({ errors, processing }) => (
                        <FormPanel
                            title={__('Update password')}
                            description={__(
                                'Use a long, random password. A password manager makes one for you.',
                            )}
                        >
                            <Field
                                htmlFor="current_password"
                                label={__('Current password')}
                                error={errors.current_password}
                            >
                                <PasswordInput
                                    id="current_password"
                                    ref={currentPasswordInput}
                                    name="current_password"
                                    autoComplete="current-password"
                                />
                            </Field>

                            <Field
                                htmlFor="password"
                                label={__('New password')}
                                help={
                                    minimum === null
                                        ? undefined
                                        : __('At least :count characters.', {
                                              count: minimum,
                                          })
                                }
                                error={errors.password}
                            >
                                <PasswordInput
                                    id="password"
                                    ref={passwordInput}
                                    name="password"
                                    autoComplete="new-password"
                                    passwordrules={passwordRules}
                                />
                            </Field>

                            <Field
                                htmlFor="password_confirmation"
                                label={__('Confirm password')}
                                error={errors.password_confirmation}
                            >
                                <PasswordInput
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    autoComplete="new-password"
                                    passwordrules={passwordRules}
                                />
                            </Field>

                            <div className="grid justify-items-start gap-2">
                                <Button
                                    type="submit"
                                    loading={processing}
                                    data-test="update-password-button"
                                    aria-describedby="update-password-note"
                                >
                                    {__('Update password')}
                                </Button>
                                <p
                                    id="update-password-note"
                                    className="text-caption text-ink-3"
                                >
                                    {__(
                                        'Updating it signs you out on your other devices.',
                                    )}
                                </p>
                            </div>
                        </FormPanel>
                    )}
                </Form>

                <TwoFactorStatus
                    available={canManageTwoFactor}
                    enabled={twoFactorEnabled}
                />
            </SettingsPage>
        </>
    );
}

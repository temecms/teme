import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { useRef } from 'react';
import SecurityController from '@/actions/App/Http/Admin/Account/SecurityController';
import { FormPanel } from '@/components/patterns/form-page';
import { SaveBar } from '@/components/patterns/save-bar';
import { SettingsPage } from '@/components/patterns/settings-page';
import PasswordInput from '@/components/password-input';
import { Field } from '@/components/ui/field';
import { __ } from '@/lib/i18n';
import { edit } from '@/routes/security';

type Props = {
    passwordRules: string;
};

/** Your account › Security: the password you sign in with. */
export default function Security({ passwordRules }: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    setLayoutProps({
        breadcrumbs: [{ title: __('Security'), href: edit() }],
    });

    return (
        <>
            <Head title={__('Security')} />

            <SettingsPage
                title={__('Security')}
                description={__('The password you sign in with.')}
            >
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
                    {({ errors, processing, isDirty, reset }) => (
                        <>
                            <FormPanel
                                title={__('Update password')}
                                description={__('Use a long, random password.')}
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
                                        placeholder={__('Current password')}
                                    />
                                </Field>

                                <Field
                                    htmlFor="password"
                                    label={__('New password')}
                                    help={__(
                                        'Updating it signs you out on your other devices.',
                                    )}
                                    error={errors.password}
                                >
                                    <PasswordInput
                                        id="password"
                                        ref={passwordInput}
                                        name="password"
                                        autoComplete="new-password"
                                        placeholder={__('New password')}
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
                                        placeholder={__('Confirm password')}
                                        passwordrules={passwordRules}
                                    />
                                </Field>
                            </FormPanel>

                            <SaveBar
                                dirty={isDirty}
                                processing={processing}
                                onDiscard={() => reset()}
                                what={__('your password')}
                                testId="update-password-button"
                            />
                        </>
                    )}
                </Form>
            </SettingsPage>
        </>
    );
}

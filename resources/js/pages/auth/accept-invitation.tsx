import { Form } from '@inertiajs/react';
import InvitationController from '@/actions/App/Http/Admin/Auth/InvitationController';
import PasswordInput from '@/components/password-input';
import { AuthPage } from '@/components/patterns/auth-page';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { __ } from '@/lib/i18n';

type Props = {
    token: string;
    email: string;
    passwordRules: string;
};

export default function AcceptInvitation({
    token,
    email,
    passwordRules,
}: Props) {
    return (
        <AuthPage
            title={__('Choose your password')}
            lead={__('Choose a password to finish setting up your account.')}
        >
            <Form
                {...InvitationController.store.form()}
                transform={(data) => ({ ...data, token, email })}
                resetOnSuccess={['password', 'password_confirmation']}
                className="grid gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        <Field
                            htmlFor="email"
                            label={__('Email')}
                            error={errors.email}
                        >
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                autoComplete="email"
                                value={email}
                                readOnly
                            />
                        </Field>

                        <Field
                            htmlFor="password"
                            label={__('Password')}
                            error={errors.password}
                        >
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="new-password"
                                autoFocus
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

                        <Button
                            type="submit"
                            variant="primary"
                            className="w-full"
                            loading={processing}
                            data-test="accept-invitation-button"
                        >
                            {__('Set password')}
                        </Button>
                    </>
                )}
            </Form>
        </AuthPage>
    );
}

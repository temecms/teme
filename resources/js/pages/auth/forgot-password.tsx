import { Form, Link } from '@inertiajs/react';
import { AuthPage } from '@/components/patterns/auth-page';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Notice } from '@/components/ui/notice';
import { TextButton } from '@/components/ui/text-button';
import { __ } from '@/lib/i18n';
import { login } from '@/routes';
import { email } from '@/routes/password';

export default function ForgotPassword({ status }: { status?: string }) {
    return (
        <AuthPage
            title={__('Forgot password')}
            lead={__('Enter your email to receive a password reset link')}
            notices={status && <Notice tone="ok">{status}</Notice>}
            links={
                <TextButton asChild className="min-h-(--ctl-h)">
                    <Link href={login()}>{__('Back to sign in')}</Link>
                </TextButton>
            }
        >
            <Form {...email.form()} className="grid gap-5">
                {({ processing, errors }) => (
                    <>
                        <Field
                            htmlFor="email"
                            label={__('Email address')}
                            error={errors.email}
                        >
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                autoComplete="off"
                                autoFocus
                            />
                        </Field>

                        <Button
                            type="submit"
                            variant="primary"
                            className="w-full"
                            loading={processing}
                            data-test="email-password-reset-link-button"
                        >
                            {__('Email password reset link')}
                        </Button>
                    </>
                )}
            </Form>
        </AuthPage>
    );
}

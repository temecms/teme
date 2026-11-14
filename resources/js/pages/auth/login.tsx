import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import FirstRunController from '@/actions/App/Http/Admin/Auth/FirstRunController';
import { SignInPaused } from '@/components/auth/sign-in-paused';
import PasswordInput from '@/components/password-input';
import { AuthPage } from '@/components/patterns/auth-page';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldInline } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Notice } from '@/components/ui/notice';
import { TextButton } from '@/components/ui/text-button';
import { __ } from '@/lib/i18n';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
    canRemember: boolean;
    firstRun?: boolean;
    /** Seconds until sign-in opens again after too many tries; null when it is open. */
    retryAfter: number | null;
};

export default function Login({
    status,
    canResetPassword,
    canRemember,
    firstRun,
    retryAfter,
}: Props) {
    const [waiting, setWaiting] = useState(retryAfter !== null);
    const [shownWait, setShownWait] = useState(retryAfter);

    // A new refusal, or none: the button follows the wait the page was given.
    if (shownWait !== retryAfter) {
        setShownWait(retryAfter);
        setWaiting(retryAfter !== null);
    }

    return (
        <AuthPage
            title={__('Sign in')}
            lead={__('Use your email address and password.')}
            notices={
                <>
                    {status && <Notice tone="ok">{status}</Notice>}
                    {firstRun && (
                        <Notice
                            tone="info"
                            data-test="first-run-notice"
                            action={
                                <TextButton asChild>
                                    <Link href={FirstRunController.show()}>
                                        {__('Set up the site')}
                                    </Link>
                                </TextButton>
                            }
                        >
                            {__('This site has no accounts yet.')}
                        </Notice>
                    )}
                    {retryAfter !== null && (
                        <SignInPaused
                            key={retryAfter}
                            seconds={retryAfter}
                            onDone={() => setWaiting(false)}
                        />
                    )}
                </>
            }
            links={
                canResetPassword && (
                    <TextButton asChild className="min-h-(--ctl-h)">
                        <Link href={request()}>
                            {__('Forgot your password?')}
                        </Link>
                    </TextButton>
                )
            }
        >
            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="grid gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        <Field
                            htmlFor="email"
                            label={__('Email address')}
                            error={
                                retryAfter === null ? errors.email : undefined
                            }
                        >
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
                                autoFocus
                                autoComplete="email"
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
                                required
                                autoComplete="current-password"
                            />
                        </Field>

                        {canRemember && (
                            <FieldInline
                                htmlFor="remember"
                                label={__('Remember me')}
                            >
                                <Checkbox id="remember" name="remember" />
                            </FieldInline>
                        )}

                        <Button
                            type="submit"
                            variant="primary"
                            className="w-full"
                            loading={processing}
                            disabled={waiting}
                            data-test="login-button"
                        >
                            {__('Sign in')}
                        </Button>
                    </>
                )}
            </Form>
        </AuthPage>
    );
}

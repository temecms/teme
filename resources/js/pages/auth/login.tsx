import { Form, Head } from '@inertiajs/react';
import { Info } from 'lucide-react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { __ } from '@/lib/i18n';
import FirstRunController from '@/actions/App/Http/Admin/Auth/FirstRunController';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
    canRemember: boolean;
    firstRun?: boolean;
};

export default function Login({
    status,
    canResetPassword,
    canRemember,
    firstRun,
}: Props) {
    return (
        <>
            <Head title="Sign in" />
            {status && (
                <div className="text-ok-ink mb-4 text-center text-sm font-medium">
                    {' '}
                    {status}{' '}
                </div>
            )}

            {firstRun && (
                <div
                    data-test="first-run-notice"
                    className="border-line bg-surface-2 mb-2 flex gap-2.5 rounded-md border px-4 py-3 text-[13px]"
                >
                    <Info
                        aria-hidden="true"
                        className="text-ink-2 mt-0.5 size-4 shrink-0"
                    />
                    <div>
                        <p>{__('This site has no accounts yet.')}</p>
                        <TextLink
                            href={FirstRunController.show()}
                            className="mt-1 inline-block font-medium"
                        >
                            {__('Set up the site')}
                        </TextLink>
                    </div>
                </div>
            )}

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>
                                <Input
                                    id="email"
                                    aria-describedby="email-error"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    autoComplete="email"
                                    placeholder="email@example.com"
                                />
                                <InputError
                                    id="email-error"
                                    message={errors.email}
                                />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center">
                                    <Label htmlFor="password">Password</Label>
                                    {canResetPassword && (
                                        <TextLink
                                            href={request()}
                                            className="ml-auto text-sm"
                                        >
                                            Forgot your password?
                                        </TextLink>
                                    )}
                                </div>
                                <PasswordInput
                                    id="password"
                                    aria-describedby="password-error"
                                    name="password"
                                    required
                                    autoComplete="current-password"
                                    placeholder="Password"
                                />
                                <InputError
                                    id="password-error"
                                    message={errors.password}
                                />
                            </div>

                            {canRemember && (
                                <div className="flex items-center space-x-3">
                                    <Checkbox id="remember" name="remember" />
                                    <Label htmlFor="remember">
                                        Remember me
                                    </Label>
                                </div>
                            )}

                            <Button
                                type="submit"
                                className="mt-4 w-full"
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                Sign in
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

Login.layout = {
    title: 'Sign in',
    description: 'Use your email address and password.',
};

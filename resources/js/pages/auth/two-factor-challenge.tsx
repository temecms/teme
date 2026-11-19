import { Form, Link } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useMemo, useState } from 'react';
import { AuthLink, AuthPage } from '@/components/patterns/auth-page';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { OTP_MAX_LENGTH } from '@/hooks/use-two-factor-auth';
import { __ } from '@/lib/i18n';
import { login } from '@/routes';
import { store } from '@/routes/two-factor/login';

export default function TwoFactorChallenge() {
    const [showRecoveryInput, setShowRecoveryInput] = useState<boolean>(false);
    const [code, setCode] = useState<string>('');

    const authConfigContent = useMemo<{
        title: string;
        description: string;
        toggleText: string;
    }>(() => {
        if (showRecoveryInput) {
            return {
                title: __('Use a recovery code'),
                description: __('Type one of your recovery codes.'),
                toggleText: __('Use your authenticator app instead'),
            };
        }

        return {
            title: __('Two-factor sign-in'),
            description: __(
                'Type the 6-digit code your authenticator app shows.',
            ),
            toggleText: __('Use a recovery code instead'),
        };
    }, [showRecoveryInput]);

    const toggleRecoveryMode = (clearErrors: () => void): void => {
        setShowRecoveryInput(!showRecoveryInput);
        clearErrors();
        setCode('');
    };

    return (
        <AuthPage
            tabTitle={__('Two-factor sign-in')}
            title={authConfigContent.title}
            lead={authConfigContent.description}
            links={
                <AuthLink asChild>
                    <Link href={login()} data-test="back-to-sign-in">
                        {__('Back to sign in')}
                    </Link>
                </AuthLink>
            }
        >
            <Form
                {...store.form()}
                className="grid gap-5"
                resetOnError
                resetOnSuccess={!showRecoveryInput}
            >
                {({ errors, processing, clearErrors }) => (
                    <>
                        {showRecoveryInput ? (
                            <Field
                                htmlFor="recovery-code"
                                label={__('Recovery code')}
                                help={__(
                                    'Use one of the codes you saved when you turned on two-factor sign-in.',
                                )}
                                error={errors.recovery_code}
                            >
                                <Input
                                    id="recovery-code"
                                    name="recovery_code"
                                    type="text"
                                    autoComplete="one-time-code"
                                    autoFocus={showRecoveryInput}
                                    required
                                />
                            </Field>
                        ) : (
                            <Field
                                htmlFor="code"
                                label={__('Authentication code')}
                                error={errors.code}
                            >
                                <InputOTP
                                    id="code"
                                    name="code"
                                    maxLength={OTP_MAX_LENGTH}
                                    value={code}
                                    onChange={(value) => setCode(value)}
                                    disabled={processing}
                                    pattern={REGEXP_ONLY_DIGITS}
                                    autoFocus
                                    containerClassName="w-full"
                                >
                                    <InputOTPGroup className="grid w-full grid-cols-6 gap-2">
                                        {Array.from(
                                            { length: OTP_MAX_LENGTH },
                                            (_, index) => (
                                                <InputOTPSlot
                                                    key={index}
                                                    index={index}
                                                    className="text-lead h-12 w-full rounded-md border font-mono"
                                                />
                                            ),
                                        )}
                                    </InputOTPGroup>
                                </InputOTP>
                            </Field>
                        )}

                        <Button
                            type="submit"
                            variant="primary"
                            className="w-full"
                            loading={processing}
                        >
                            {__('Continue')}
                        </Button>

                        <AuthLink
                            className="justify-self-center"
                            data-test="two-factor-toggle"
                            onClick={() => toggleRecoveryMode(clearErrors)}
                        >
                            {authConfigContent.toggleText}
                        </AuthLink>
                    </>
                )}
            </Form>
        </AuthPage>
    );
}

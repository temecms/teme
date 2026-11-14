import { Form } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useMemo, useState } from 'react';
import { AuthPage } from '@/components/patterns/auth-page';
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
                title: __('Recovery code'),
                description: __('Type one of your recovery codes.'),
                toggleText: __('Use your authenticator app instead'),
            };
        }

        return {
            title: __('Authentication code'),
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
                                >
                                    <InputOTPGroup>
                                        {Array.from(
                                            { length: OTP_MAX_LENGTH },
                                            (_, index) => (
                                                <InputOTPSlot
                                                    key={index}
                                                    index={index}
                                                    className="max-sm:size-10"
                                                />
                                            ),
                                        )}
                                    </InputOTPGroup>
                                </InputOTP>
                            </Field>
                        )}

                        <div className="grid gap-3">
                            <Button
                                type="submit"
                                variant="primary"
                                className="w-full"
                                loading={processing}
                            >
                                {__('Continue')}
                            </Button>

                            <Button
                                type="button"
                                variant="neutral"
                                className="w-full"
                                onClick={() => toggleRecoveryMode(clearErrors)}
                            >
                                {authConfigContent.toggleText}
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </AuthPage>
    );
}

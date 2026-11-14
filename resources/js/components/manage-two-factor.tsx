import { Form } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import { FormPanel } from '@/components/patterns/form-page';
import TwoFactorRecoveryCodes from '@/components/two-factor-recovery-codes';
import TwoFactorSetupModal from '@/components/two-factor-setup-modal';
import { Button } from '@/components/ui/button';
import { useTwoFactorAuth } from '@/hooks/use-two-factor-auth';
import { __ } from '@/lib/i18n';
import { disable, enable } from '@/routes/two-factor';

export type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

/**
 * Two-factor sign-in's one panel: whether it is on, turning it on with the
 * setup dialog or off after asking, and the recovery codes. Fortify does
 * the work (I-100).
 */
export default function ManageTwoFactor(props: Props) {
    const requiresConfirmation = props.requiresConfirmation ?? false;
    const twoFactorEnabled = props.twoFactorEnabled ?? false;

    const {
        qrCodeSvg,
        hasSetupData,
        manualSetupKey,
        clearSetupData,
        clearTwoFactorAuthData,
        fetchSetupData,
        recoveryCodesList,
        fetchRecoveryCodes,
        errors,
    } = useTwoFactorAuth();
    const [showSetupModal, setShowSetupModal] = useState<boolean>(false);
    const prevTwoFactorEnabled = useRef(twoFactorEnabled);

    useEffect(() => {
        if (prevTwoFactorEnabled.current && !twoFactorEnabled) {
            clearTwoFactorAuthData();
        }

        prevTwoFactorEnabled.current = twoFactorEnabled;
    }, [twoFactorEnabled, clearTwoFactorAuthData]);

    if (!(props.canManageTwoFactor ?? false)) {
        return null;
    }

    return (
        <FormPanel title={__('Two-factor sign-in')}>
            <p className="text-body text-ink-2">
                {twoFactorEnabled
                    ? __(
                          'When you sign in, you also type the code from the authenticator app on your phone.',
                      )
                    : __(
                          'Once it’s on, signing in also asks for a code from an authenticator app on your phone.',
                      )}
            </p>

            {twoFactorEnabled ? (
                <>
                    <div>
                        <Form {...disable.form()}>
                            {({ processing }) => (
                                <ConfirmAction
                                    title={__('Turn off two-factor sign-in?')}
                                    description={__(
                                        'Signing in will need only your password until you set it up again, with a new authenticator entry and new recovery codes.',
                                    )}
                                    confirmLabel={__(
                                        'Turn off two-factor sign-in',
                                    )}
                                >
                                    <Button
                                        variant="danger-quiet"
                                        type="submit"
                                        disabled={processing}
                                    >
                                        {__('Turn off…')}
                                    </Button>
                                </ConfirmAction>
                            )}
                        </Form>
                    </div>

                    <TwoFactorRecoveryCodes
                        recoveryCodesList={recoveryCodesList}
                        fetchRecoveryCodes={fetchRecoveryCodes}
                        errors={errors}
                    />
                </>
            ) : (
                <div>
                    {hasSetupData ? (
                        <Button onClick={() => setShowSetupModal(true)}>
                            <ShieldCheck />
                            {__('Continue setup')}
                        </Button>
                    ) : (
                        <Form
                            {...enable.form()}
                            onSuccess={() => setShowSetupModal(true)}
                        >
                            {({ processing }) => (
                                <Button type="submit" disabled={processing}>
                                    {__('Turn on two-factor sign-in')}
                                </Button>
                            )}
                        </Form>
                    )}
                </div>
            )}

            <TwoFactorSetupModal
                isOpen={showSetupModal}
                onClose={() => setShowSetupModal(false)}
                requiresConfirmation={requiresConfirmation}
                twoFactorEnabled={twoFactorEnabled}
                qrCodeSvg={qrCodeSvg}
                manualSetupKey={manualSetupKey}
                clearSetupData={clearSetupData}
                fetchSetupData={fetchSetupData}
                errors={errors}
            />
        </FormPanel>
    );
}

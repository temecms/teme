import { Form } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { Check, Copy, ScanLine } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import AlertError from '@/components/alert-error';
import InputError from '@/components/input-error';
import { ResponsiveDialogContent } from '@/components/patterns/responsive-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { IconTile } from '@/components/ui/icon-tile';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Spinner } from '@/components/ui/spinner';
import { useAppearance } from '@/hooks/use-appearance';
import { useClipboard } from '@/hooks/use-clipboard';
import { OTP_MAX_LENGTH } from '@/hooks/use-two-factor-auth';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { confirm } from '@/routes/two-factor';

function TwoFactorSetupStep({
    qrCodeSvg,
    manualSetupKey,
    buttonText,
    onNextStep,
    errors,
}: {
    qrCodeSvg: string | null;
    manualSetupKey: string | null;
    buttonText: string;
    onNextStep: () => void;
    errors: string[];
}) {
    const { resolvedAppearance } = useAppearance();
    const [copiedText, copy] = useClipboard();
    const IconComponent = copiedText === manualSetupKey ? Check : Copy;

    return (
        <>
            {errors?.length ? (
                <AlertError errors={errors} />
            ) : (
                <>
                    <div className="mx-auto flex max-w-md overflow-hidden">
                        <div className="border-line mx-auto aspect-square w-64 rounded-lg border">
                            <div className="flex h-full w-full items-center justify-center p-5">
                                {qrCodeSvg ? (
                                    <div
                                        className={cn(
                                            'bg-paper aspect-square w-full rounded-lg p-2 [&_svg]:size-full',
                                            resolvedAppearance === 'dark' &&
                                                'brightness-150 invert',
                                        )}
                                        dangerouslySetInnerHTML={{
                                            __html: qrCodeSvg,
                                        }}
                                    />
                                ) : (
                                    <Spinner />
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="flex w-full">
                        <Button className="w-full" onClick={onNextStep}>
                            {buttonText}
                        </Button>
                    </div>

                    <div className="relative flex w-full items-center justify-center">
                        <div className="bg-line absolute inset-0 top-1/2 h-px w-full" />
                        <span className="bg-surface relative px-2 py-1">
                            {__('or type this setup key')}
                        </span>
                    </div>

                    <div className="flex w-full gap-2">
                        {!manualSetupKey ? (
                            <div className="bg-surface-2 flex w-full items-center justify-center rounded-md p-3">
                                <Spinner />
                            </div>
                        ) : (
                            <>
                                <Input
                                    readOnly
                                    value={manualSetupKey}
                                    aria-label={__('Setup key')}
                                    className="font-mono"
                                />
                                <Button
                                    type="button"
                                    variant="neutral"
                                    size="icon"
                                    aria-label={__('Copy the setup key')}
                                    onClick={() => copy(manualSetupKey)}
                                >
                                    <IconComponent aria-hidden="true" />
                                </Button>
                            </>
                        )}
                    </div>
                </>
            )}
        </>
    );
}

function TwoFactorVerificationStep({
    onClose,
    onBack,
}: {
    onClose: () => void;
    onBack: () => void;
}) {
    const [code, setCode] = useState<string>('');
    const pinInputContainerRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        setTimeout(() => {
            pinInputContainerRef.current?.querySelector('input')?.focus();
        }, 0);
    }, []);

    return (
        <Form
            {...confirm.form()}
            onSuccess={() => onClose()}
            resetOnError
            resetOnSuccess
        >
            {({
                processing,
                errors,
            }: {
                processing: boolean;
                errors?: { confirmTwoFactorAuthentication?: { code?: string } };
            }) => (
                <>
                    <div
                        ref={pinInputContainerRef}
                        className="relative w-full space-y-3"
                    >
                        <div className="flex w-full flex-col items-center space-y-3 py-2">
                            <InputOTP
                                id="otp"
                                aria-label={__('Authentication code')}
                                aria-describedby="otp-error"
                                name="code"
                                maxLength={OTP_MAX_LENGTH}
                                onChange={setCode}
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
                                            />
                                        ),
                                    )}
                                </InputOTPGroup>
                            </InputOTP>
                            <InputError
                                id="otp-error"
                                message={
                                    errors?.confirmTwoFactorAuthentication?.code
                                }
                            />
                        </div>

                        <div className="flex w-full gap-3">
                            <Button
                                type="button"
                                variant="neutral"
                                className="flex-1"
                                onClick={onBack}
                                disabled={processing}
                            >
                                {__('Back')}
                            </Button>
                            <Button
                                type="submit"
                                className="flex-1"
                                disabled={
                                    processing || code.length < OTP_MAX_LENGTH
                                }
                            >
                                {__('Confirm')}
                            </Button>
                        </div>
                    </div>
                </>
            )}
        </Form>
    );
}

type Props = {
    isOpen: boolean;
    onClose: () => void;
    requiresConfirmation: boolean;
    twoFactorEnabled: boolean;
    qrCodeSvg: string | null;
    manualSetupKey: string | null;
    clearSetupData: () => void;
    fetchSetupData: () => Promise<void>;
    errors: string[];
};

export default function TwoFactorSetupModal({
    isOpen,
    onClose,
    requiresConfirmation,
    twoFactorEnabled,
    qrCodeSvg,
    manualSetupKey,
    clearSetupData,
    fetchSetupData,
    errors,
}: Props) {
    const [showVerificationStep, setShowVerificationStep] =
        useState<boolean>(false);

    const modalConfig = useMemo<{
        title: string;
        description: string;
        buttonText: string;
    }>(() => {
        if (twoFactorEnabled) {
            return {
                title: __('Two-factor sign-in is on'),
                description: __(
                    'Scan the QR code, or type the setup key, in your authenticator app.',
                ),
                buttonText: __('Close'),
            };
        }

        if (showVerificationStep) {
            return {
                title: __('Verify authentication code'),
                description: __(
                    'Enter the 6-digit code from your authenticator app',
                ),
                buttonText: __('Continue'),
            };
        }

        return {
            title: __('Turn on two-factor sign-in'),
            description: __(
                'Scan the QR code, or type the setup key, in your authenticator app.',
            ),
            buttonText: __('Continue'),
        };
    }, [twoFactorEnabled, showVerificationStep]);

    const resetModalState = useCallback(() => {
        if (twoFactorEnabled) {
            clearSetupData();
        }

        setShowVerificationStep(false);
    }, [clearSetupData, twoFactorEnabled]);

    const handleClose = useCallback(() => {
        resetModalState();
        onClose();
    }, [onClose, resetModalState]);

    const handleModalNextStep = useCallback(() => {
        if (requiresConfirmation) {
            setShowVerificationStep(true);

            return;
        }

        clearSetupData();
        handleClose();
    }, [requiresConfirmation, clearSetupData, handleClose]);

    const fetchSetupDataRef = useRef(fetchSetupData);

    useEffect(() => {
        fetchSetupDataRef.current = fetchSetupData;
    }, [fetchSetupData]);

    useEffect(() => {
        if (isOpen && !qrCodeSvg) {
            void fetchSetupDataRef.current();
        }
    }, [isOpen, qrCodeSvg]);

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && handleClose()}>
            <ResponsiveDialogContent size="sm">
                <DialogHeader className="flex items-center justify-center">
                    <IconTile icon={ScanLine} size="lg" className="mb-3" />
                    <DialogTitle>{modalConfig.title}</DialogTitle>
                    <DialogDescription className="text-center">
                        {modalConfig.description}
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col items-center space-y-5">
                    {showVerificationStep ? (
                        <TwoFactorVerificationStep
                            onClose={handleClose}
                            onBack={() => setShowVerificationStep(false)}
                        />
                    ) : (
                        <TwoFactorSetupStep
                            qrCodeSvg={qrCodeSvg}
                            manualSetupKey={manualSetupKey}
                            buttonText={modalConfig.buttonText}
                            onNextStep={handleModalNextStep}
                            errors={errors}
                        />
                    )}
                </div>
            </ResponsiveDialogContent>
        </Dialog>
    );
}

import { Form, Head, Link, router, setLayoutProps } from '@inertiajs/react';
import { Copy, Download } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import AlertError from '@/components/alert-error';
import {
    RecoveryCodeList,
    TwoFactorStatus,
} from '@/components/account/two-factor-status';
import { FormPanel } from '@/components/patterns/form-page';
import { SettingsPage } from '@/components/patterns/settings-page';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldInline } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Notice } from '@/components/ui/notice';
import { Spinner } from '@/components/ui/spinner';
import { StepBar } from '@/components/ui/step-bar';
import { useAppearance } from '@/hooks/use-appearance';
import { useClipboard } from '@/hooks/use-clipboard';
import { OTP_MAX_LENGTH, useTwoFactorAuth } from '@/hooks/use-two-factor-auth';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { edit as editSecurity } from '@/routes/security';
import { confirm, edit, enable } from '@/routes/two-factor';

type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

type Step = 'scan' | 'code' | 'codes';

/** The recovery codes as a file to keep, one to a line. */
function downloadCodes(codes: string[]): void {
    const link = document.createElement('a');

    link.href = URL.createObjectURL(
        new Blob([codes.join('\n') + '\n'], { type: 'text/plain' }),
    );
    link.download = 'teme-recovery-codes.txt';
    link.click();
    URL.revokeObjectURL(link.href);
}

/**
 * Your account › Two-factor sign-in: three steps in the page, under
 * a step bar: scan the code, type a code, keep the recovery codes; then the
 * panel that says it is on. Fortify does the work: its secret is
 * made as the page opens, and the code typed in step 2 turns it on.
 */
export default function TwoFactor({
    canManageTwoFactor = false,
    twoFactorEnabled = false,
}: Props) {
    const title = __('Two-factor sign-in');
    const description = __(
        'A second step when you sign in: a code from an authenticator app.',
    );

    setLayoutProps({
        breadcrumbs: [{ title, href: edit() }],
        title,
        description,
    });

    return (
        <>
            <Head title={title} />

            <SettingsPage title={title} description={description} titleAs="div">
                {!canManageTwoFactor ? (
                    <Notice tone="info">
                        {__('Two-factor sign-in is turned off for this site.')}
                    </Notice>
                ) : twoFactorEnabled ? (
                    <TwoFactorStatus available enabled />
                ) : (
                    <SetUpSteps />
                )}
            </SettingsPage>
        </>
    );
}

/** The three steps that turn two-factor sign-in on. */
function SetUpSteps() {
    const {
        qrCodeSvg,
        manualSetupKey,
        fetchSetupData,
        recoveryCodesList,
        fetchRecoveryCodes,
        errors,
    } = useTwoFactorAuth();
    const [step, setStep] = useState<Step>('scan');
    const [saved, setSaved] = useState(false);
    const started = useRef(false);

    // The secret is made once, as the steps open; Fortify keeps an unconfirmed one.
    useEffect(() => {
        if (started.current) {
            return;
        }

        started.current = true;
        router.post(
            enable.url(),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => void fetchSetupData(),
            },
        );
    }, [fetchSetupData]);

    const steps = [
        __('Scan the code'),
        __('Type a code'),
        __('Keep your recovery codes'),
    ];
    const at = { scan: 0, code: 1, codes: 2 }[step];

    return (
        <>
            <StepBar
                steps={steps}
                current={at}
                aria-label={__('Steps')}
                data-test="two-factor-steps"
            />
            {step === 'scan' && (
                <ScanStep
                    qrCodeSvg={qrCodeSvg}
                    manualSetupKey={manualSetupKey}
                    errors={errors}
                    onNext={() => setStep('code')}
                />
            )}
            {step === 'code' && (
                <CodeStep
                    onBack={() => setStep('scan')}
                    onConfirmed={() => {
                        void fetchRecoveryCodes();
                        setStep('codes');
                    }}
                />
            )}
            {step === 'codes' && (
                <FormPanel title={__('3. Keep your recovery codes')}>
                    <Notice tone="warn" role="status">
                        {__(
                            'You see these once. Each code gets you in one time, if you lose your phone. Save them somewhere safe, like a password manager.',
                        )}
                    </Notice>
                    {recoveryCodesList.length > 0 ? (
                        <RecoveryCodeList codes={recoveryCodesList} />
                    ) : (
                        <Spinner />
                    )}
                    <CodeActions codes={recoveryCodesList} />
                    <FieldInline
                        htmlFor="codes-saved"
                        label={__('I’ve saved these codes')}
                    >
                        <Checkbox
                            id="codes-saved"
                            checked={saved}
                            data-test="codes-saved"
                            onCheckedChange={(next) => setSaved(next === true)}
                        />
                    </FieldInline>
                    <div>
                        <Button
                            type="button"
                            disabled={!saved}
                            data-test="finish-two-factor"
                            onClick={() =>
                                router.reload({
                                    only: ['twoFactorEnabled'],
                                })
                            }
                        >
                            {__('Turn on two-factor sign-in')}
                        </Button>
                    </div>
                </FormPanel>
            )}
        </>
    );
}

/** Copy and Download for the recovery codes. */
function CodeActions({ codes }: { codes: string[] }) {
    const [copied, copy] = useClipboard();

    return (
        <div className="flex flex-wrap gap-2">
            <Button
                type="button"
                variant="neutral"
                disabled={codes.length === 0}
                onClick={() => void copy(codes.join('\n'))}
            >
                <Copy aria-hidden="true" />
                {copied === codes.join('\n') ? __('Copied') : __('Copy')}
            </Button>
            <Button
                type="button"
                variant="neutral"
                disabled={codes.length === 0}
                onClick={() => downloadCodes(codes)}
            >
                <Download aria-hidden="true" />
                {__('Download')}
            </Button>
        </div>
    );
}

/** Step 1: the QR code and the key, for an authenticator app. */
function ScanStep({
    qrCodeSvg,
    manualSetupKey,
    errors,
    onNext,
}: {
    qrCodeSvg: string | null;
    manualSetupKey: string | null;
    errors: string[];
    onNext: () => void;
}) {
    const { resolvedAppearance } = useAppearance();
    const [copied, copy] = useClipboard();

    return (
        <FormPanel
            title={__('1. Scan the code with an authenticator app')}
            description={__(
                'Open an authenticator app on your phone, such as the one your phone or password manager already has, and scan this square.',
            )}
        >
            {errors.length > 0 ? (
                <AlertError errors={errors} />
            ) : (
                <div className="flex flex-wrap items-center gap-6">
                    <div className="border-line grid size-48 shrink-0 place-items-center rounded-lg border p-3">
                        {qrCodeSvg ? (
                            <div
                                data-test="two-factor-qr"
                                className={cn(
                                    'bg-paper aspect-square w-full rounded-md p-2 [&_svg]:size-full',
                                    resolvedAppearance === 'dark' &&
                                        'brightness-150 invert',
                                )}
                                dangerouslySetInnerHTML={{ __html: qrCodeSvg }}
                            />
                        ) : (
                            <Spinner />
                        )}
                    </div>
                    <div className="grid min-w-0 flex-1 basis-60 gap-2">
                        <p className="text-body text-ink-2">
                            {__(
                                'Can’t scan it? Type this key into the app instead.',
                            )}
                        </p>
                        <div className="flex flex-wrap items-center gap-2">
                            <code
                                aria-label={__('Setup key')}
                                data-test="two-factor-key"
                                className="bg-surface-2 text-body rounded-sm px-2 py-1 font-mono break-all"
                            >
                                {manualSetupKey ?? '…'}
                            </code>
                            <Button
                                type="button"
                                variant="neutral"
                                size="sm"
                                disabled={manualSetupKey === null}
                                onClick={() =>
                                    manualSetupKey !== null &&
                                    void copy(manualSetupKey)
                                }
                            >
                                <Copy aria-hidden="true" />
                                {copied === manualSetupKey
                                    ? __('Copied')
                                    : __('Copy key')}
                            </Button>
                        </div>
                    </div>
                </div>
            )}
            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    data-test="two-factor-next"
                    disabled={qrCodeSvg === null}
                    onClick={onNext}
                >
                    {__('Next: type a code')}
                </Button>
                <Button type="button" variant="ghost" asChild>
                    <Link href={editSecurity()}>{__('Cancel')}</Link>
                </Button>
            </div>
        </FormPanel>
    );
}

/** Step 2: the code the app shows, which turns two-factor sign-in on. */
function CodeStep({
    onBack,
    onConfirmed,
}: {
    onBack: () => void;
    onConfirmed: () => void;
}) {
    return (
        <Form
            {...confirm.form()}
            options={{ preserveScroll: true, preserveState: true }}
            onSuccess={onConfirmed}
            resetOnError
        >
            {({
                processing,
                errors,
            }: {
                processing: boolean;
                errors?: {
                    confirmTwoFactorAuthentication?: { code?: string };
                };
            }) => (
                <FormPanel
                    title={__('2. Type the code the app shows')}
                    description={__(
                        'The app shows a new six-digit code every 30 seconds. Type the one it shows now, to prove it’s set up.',
                    )}
                >
                    <Field
                        htmlFor="code"
                        label={__('Six-digit code')}
                        error={errors?.confirmTwoFactorAuthentication?.code}
                    >
                        <Input
                            id="code"
                            name="code"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            maxLength={OTP_MAX_LENGTH}
                            pattern="[0-9]*"
                            autoFocus
                            className="w-40 font-mono"
                        />
                    </Field>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="submit"
                            loading={processing}
                            data-test="two-factor-check"
                        >
                            {__('Check the code')}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={onBack}
                            disabled={processing}
                        >
                            {__('Back')}
                        </Button>
                    </div>
                </FormPanel>
            )}
        </Form>
    );
}

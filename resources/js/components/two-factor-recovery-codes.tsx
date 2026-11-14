import { Form } from '@inertiajs/react';
import { Eye, EyeOff, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import AlertError from '@/components/alert-error';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { __ } from '@/lib/i18n';
import { regenerateRecoveryCodes } from '@/routes/two-factor';

type Props = {
    recoveryCodesList: string[];
    fetchRecoveryCodes: () => Promise<void>;
    errors: string[];
};

/**
 * The recovery codes under Two-factor sign-in: hidden until asked for,
 * each signing in once, and regenerated together.
 */
export default function TwoFactorRecoveryCodes({
    recoveryCodesList,
    fetchRecoveryCodes,
    errors,
}: Props) {
    const [codesAreVisible, setCodesAreVisible] = useState<boolean>(false);
    const codesSectionRef = useRef<HTMLDivElement | null>(null);
    const canRegenerateCodes = recoveryCodesList.length > 0 && codesAreVisible;

    const toggleCodesVisibility = useCallback(async () => {
        if (!codesAreVisible && !recoveryCodesList.length) {
            await fetchRecoveryCodes();
        }

        setCodesAreVisible(!codesAreVisible);

        if (!codesAreVisible) {
            setTimeout(() => {
                codesSectionRef.current?.scrollIntoView({
                    block: 'nearest',
                });
            });
        }
    }, [codesAreVisible, recoveryCodesList.length, fetchRecoveryCodes]);

    useEffect(() => {
        if (!recoveryCodesList.length) {
            void fetchRecoveryCodes();
        }
    }, [recoveryCodesList.length, fetchRecoveryCodes]);

    const RecoveryCodeIconComponent = codesAreVisible ? EyeOff : Eye;

    return (
        <section
            aria-labelledby="recovery-codes-heading"
            className="border-line grid gap-3 border-t pt-5"
        >
            <div className="grid gap-1">
                <h3
                    id="recovery-codes-heading"
                    className="text-body text-ink font-semibold"
                >
                    {__('Recovery codes')}
                </h3>
                <p className="text-body text-ink-2">
                    {__(
                        'Each code signs you in once if you lose your phone. Keep them in a password manager.',
                    )}
                </p>
            </div>
            <div className="flex flex-col gap-3 select-none sm:flex-row sm:items-center sm:justify-between">
                <Button
                    variant="neutral"
                    onClick={toggleCodesVisibility}
                    className="w-fit"
                    aria-expanded={codesAreVisible}
                    aria-controls="recovery-codes-section"
                >
                    <RecoveryCodeIconComponent aria-hidden="true" />
                    {codesAreVisible
                        ? __('Hide recovery codes')
                        : __('View recovery codes')}
                </Button>

                {canRegenerateCodes && (
                    <Form
                        {...regenerateRecoveryCodes.form()}
                        options={{ preserveScroll: true }}
                        onSuccess={fetchRecoveryCodes}
                    >
                        {({ processing }) => (
                            <div className="flex flex-wrap items-center gap-2">
                                <Button
                                    variant="neutral"
                                    type="submit"
                                    disabled={processing}
                                    aria-describedby="regenerate-warning"
                                >
                                    <RefreshCw aria-hidden="true" />
                                    {__('Regenerate codes')}
                                </Button>
                                <p
                                    id="regenerate-warning"
                                    className="text-caption text-ink-3"
                                >
                                    {__(
                                        'New codes replace these. The old ones stop working.',
                                    )}
                                </p>
                            </div>
                        )}
                    </Form>
                )}
            </div>
            <div id="recovery-codes-section" hidden={!codesAreVisible}>
                {errors?.length ? (
                    <AlertError errors={errors} />
                ) : (
                    <div
                        ref={codesSectionRef}
                        className="bg-surface-2 text-body grid gap-1 rounded-lg p-4 font-mono"
                        role="list"
                        aria-label={__('Recovery codes')}
                    >
                        {recoveryCodesList.length ? (
                            recoveryCodesList.map((code, index) => (
                                <div
                                    key={index}
                                    role="listitem"
                                    className="select-text"
                                >
                                    {code}
                                </div>
                            ))
                        ) : (
                            <div
                                className="grid gap-2"
                                aria-label={__('Loading recovery codes')}
                            >
                                {Array.from({ length: 8 }, (_, index) => (
                                    <Skeleton key={index} aria-hidden="true" />
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </div>
        </section>
    );
}

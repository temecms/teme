import { Form, Link } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { useState } from 'react';
import AlertError from '@/components/alert-error';
import { ConfirmAction } from '@/components/confirm-action';
import { Button } from '@/components/ui/button';
import { StatusPill } from '@/components/ui/status-pill';
import { useTwoFactorAuth } from '@/hooks/use-two-factor-auth';
import { __ } from '@/lib/i18n';
import { disable, edit as editTwoFactor } from '@/routes/two-factor';

/**
 * The recovery codes as a list, eight to a panel, each signing in once.
 */
export function RecoveryCodeList({ codes }: { codes: string[] }) {
    return (
        <ul
            aria-label={__('Recovery codes')}
            data-test="recovery-codes"
            className="bg-surface-2 text-body grid gap-x-6 gap-y-1 rounded-md p-4 font-mono select-text sm:grid-cols-2"
        >
            {codes.map((code) => (
                <li key={code}>{code}</li>
            ))}
        </ul>
    );
}

/**
 * Two-factor sign-in once it is set: a panel saying whether it is on, with
 * "Show recovery codes" and "Turn off…" while it is, or the way to set it
 * up while it isn't. Security shows it under the password, and Two-factor
 * sign-in shows it once the steps are done. Fortify does the work.
 */
export function TwoFactorStatus({
    available,
    enabled,
    headingLevel = 'h2',
}: {
    /** Whether the site lets people turn it on at all. */
    available: boolean;
    enabled: boolean;
    headingLevel?: 'h2' | 'h3';
}) {
    const { recoveryCodesList, fetchRecoveryCodes, errors } =
        useTwoFactorAuth();
    const [showing, setShowing] = useState(false);
    const Heading = headingLevel;

    const pill = !available ? (
        <StatusPill tone="neutral" flat>
            {__('Not available')}
        </StatusPill>
    ) : enabled ? (
        <StatusPill tone="ok">{__('On')}</StatusPill>
    ) : (
        <StatusPill tone="neutral">{__('Off')}</StatusPill>
    );

    return (
        <section
            data-slot="form-panel"
            data-test="two-factor-status"
            aria-labelledby="two-factor-status-heading"
            className="bg-surface shadow-raised grid gap-4 rounded-lg p-6"
        >
            <div className="flex flex-wrap items-center gap-3">
                <Heading
                    id="two-factor-status-heading"
                    className="text-lead text-ink flex-1 font-semibold"
                >
                    {__('Two-factor sign-in')}
                </Heading>
                {pill}
            </div>
            <p className="text-body text-ink-2">
                {!available
                    ? __('Two-factor sign-in is turned off for this site.')
                    : enabled
                      ? __(
                            'When you sign in, you also type a code from your authenticator app. If you lose your phone, a recovery code gets you in.',
                        )
                      : __(
                            'Add a second step to signing in: a code from an authenticator app on your phone.',
                        )}
            </p>
            {available && enabled && (
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="neutral"
                        size="sm"
                        aria-expanded={showing}
                        aria-controls="two-factor-codes"
                        data-test="show-recovery-codes"
                        onClick={async () => {
                            if (!showing && recoveryCodesList.length === 0) {
                                await fetchRecoveryCodes();
                            }

                            setShowing(!showing);
                        }}
                    >
                        <KeyRound aria-hidden="true" />
                        {__('Show recovery codes')}
                    </Button>
                    <Form {...disable.form()}>
                        {({ processing }) => (
                            <ConfirmAction
                                title={__('Turn off two-factor sign-in?')}
                                description={__(
                                    'You will sign in with your password alone, and your recovery codes stop working.',
                                )}
                                confirmLabel={__('Turn off')}
                            >
                                <Button
                                    variant="danger-quiet"
                                    size="sm"
                                    type="submit"
                                    disabled={processing}
                                    data-test="turn-off-two-factor"
                                >
                                    {__('Turn off…')}
                                </Button>
                            </ConfirmAction>
                        )}
                    </Form>
                </div>
            )}
            {available && !enabled && (
                <div>
                    <Button asChild>
                        <Link href={editTwoFactor()}>
                            {__('Set up two-factor sign-in')}
                        </Link>
                    </Button>
                </div>
            )}
            {showing && (
                <div id="two-factor-codes" className="grid gap-2">
                    {errors.length > 0 ? (
                        <AlertError errors={errors} />
                    ) : (
                        <>
                            <p className="text-body text-ink-2">
                                {__(
                                    'Each code works once. Keep them somewhere safe.',
                                )}
                            </p>
                            <RecoveryCodeList codes={recoveryCodesList} />
                        </>
                    )}
                </div>
            )}
        </section>
    );
}

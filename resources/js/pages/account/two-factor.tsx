import { Head, setLayoutProps } from '@inertiajs/react';
import ManageTwoFactor from '@/components/manage-two-factor';
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import { SettingsPage } from '@/components/patterns/settings-page';
import { Notice } from '@/components/ui/notice';
import { __ } from '@/lib/i18n';
import { edit } from '@/routes/two-factor';

/**
 * Your account › Two-factor sign-in (I-115): turning it on or off, and the
 * recovery codes, or a word that the site has it turned off.
 */
export default function TwoFactor(props: ManageTwoFactorProps) {
    setLayoutProps({
        breadcrumbs: [{ title: __('Two-factor sign-in'), href: edit() }],
    });

    return (
        <>
            <Head title={__('Two-factor sign-in')} />

            <SettingsPage
                title={__('Two-factor sign-in')}
                description={__(
                    'A second step when you sign in: a code from an authenticator app.',
                )}
            >
                {props.canManageTwoFactor ? (
                    <ManageTwoFactor
                        canManageTwoFactor={props.canManageTwoFactor}
                        requiresConfirmation={props.requiresConfirmation}
                        twoFactorEnabled={props.twoFactorEnabled}
                    />
                ) : (
                    <Notice tone="info">
                        {__('Two-factor sign-in is turned off for this site.')}
                    </Notice>
                )}
            </SettingsPage>
        </>
    );
}

import { Form, Link, usePage } from '@inertiajs/react';
import { Mail } from 'lucide-react';
import { AuthLink, AuthPage } from '@/components/patterns/auth-page';
import { Button } from '@/components/ui/button';
import { IconTile } from '@/components/ui/icon-tile';
import { Notice } from '@/components/ui/notice';
import { __ } from '@/lib/i18n';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

/**
 * Check your email: the address a confirmation link went to, the link sent
 * again on asking, and signing out.
 */
export default function VerifyEmail({ status }: { status?: string }) {
    const { auth } = usePage().props;

    return (
        <AuthPage
            title={__('Check your email')}
            icon={
                <IconTile
                    icon={Mail}
                    size="lg"
                    className="bg-info-soft text-info-ink"
                />
            }
            lead={__(
                'We sent a link to :email. Open it to confirm that this address is yours.',
                { email: auth.user.email },
            )}
            notices={
                status === 'verification-link-sent' && (
                    <Notice tone="ok">
                        {__('Sent. Check your email for the new link.')}
                    </Notice>
                )
            }
            links={
                <AuthLink asChild>
                    <Link href={logout()}>{__('Sign out')}</Link>
                </AuthLink>
            }
        >
            <Form {...send.form()} className="grid">
                {({ processing }) => (
                    <Button
                        type="submit"
                        variant="neutral"
                        className="w-full"
                        loading={processing}
                    >
                        {__('Send the link again')}
                    </Button>
                )}
            </Form>
        </AuthPage>
    );
}

import { Form, Link } from '@inertiajs/react';
import { AuthPage } from '@/components/patterns/auth-page';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { TextButton } from '@/components/ui/text-button';
import { __ } from '@/lib/i18n';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status?: string }) {
    return (
        <AuthPage
            title={__('Email verification')}
            lead={__('Click the link we emailed you to confirm your address.')}
            notices={
                status === 'verification-link-sent' && (
                    <Notice tone="ok">
                        {__('Sent. Check your email for the new link.')}
                    </Notice>
                )
            }
            links={
                <TextButton asChild className="min-h-(--ctl-h)">
                    <Link href={logout()}>{__('Sign out')}</Link>
                </TextButton>
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
                        {__('Resend verification email')}
                    </Button>
                )}
            </Form>
        </AuthPage>
    );
}

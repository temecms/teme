import { Form, Link } from '@inertiajs/react';
import { Mail } from 'lucide-react';
import { useState } from 'react';
import { AuthLink, AuthPage } from '@/components/patterns/auth-page';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { IconTile } from '@/components/ui/icon-tile';
import { Input } from '@/components/ui/input';
import { __ } from '@/lib/i18n';
import { login } from '@/routes';
import { email as sendLink } from '@/routes/password';

/**
 * Forgotten your password? Ask for a reset link by email; once it is sent,
 * the card says to check the email, names the address, and sends it again
 * on asking.
 */
export default function ForgotPassword({
    status,
    minutes,
}: {
    status?: string;
    /** How long a reset link works. */
    minutes: number;
}) {
    const [typed, setTyped] = useState('');

    if (status) {
        return (
            <AuthPage
                title={__('Check your email')}
                icon={
                    <IconTile
                        icon={Mail}
                        size="lg"
                        className="bg-ok-soft text-ok-ink"
                    />
                }
                lead={
                    typed === ''
                        ? __(
                              'If that address has an account here, we have sent a link to choose a new password. It works for :minutes minutes.',
                              { minutes },
                          )
                        : __(
                              'If :email has an account here, we have sent a link to choose a new password. It works for :minutes minutes.',
                              { email: typed, minutes },
                          )
                }
                links={
                    <>
                        {typed !== '' && (
                            <Form
                                {...sendLink.form()}
                                options={{ preserveState: true }}
                                className="contents"
                            >
                                {({ processing }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="email"
                                            value={typed}
                                        />
                                        <AuthLink
                                            type="submit"
                                            disabled={processing}
                                            data-test="send-reset-again"
                                        >
                                            {__(
                                                'Nothing arrived? Send it again',
                                            )}
                                        </AuthLink>
                                    </>
                                )}
                            </Form>
                        )}
                        <AuthLink asChild>
                            <Link href={login()}>{__('Back to sign in')}</Link>
                        </AuthLink>
                    </>
                }
            >
                {null}
            </AuthPage>
        );
    }

    return (
        <AuthPage
            title={__('Forgotten your password?')}
            lead={__(
                'Type your email address and we will send you a link to choose a new one.',
            )}
            links={
                <AuthLink asChild>
                    <Link href={login()}>{__('Back to sign in')}</Link>
                </AuthLink>
            }
        >
            <Form
                {...sendLink.form()}
                options={{ preserveState: true }}
                className="grid gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        <Field
                            htmlFor="email"
                            label={__('Email address')}
                            error={errors.email}
                        >
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                autoComplete="email"
                                placeholder={__('email@example.com')}
                                autoFocus
                                onChange={(event) =>
                                    setTyped(event.target.value.trim())
                                }
                            />
                        </Field>

                        <Button
                            type="submit"
                            variant="primary"
                            className="w-full"
                            loading={processing}
                            data-test="email-password-reset-link-button"
                        >
                            {__('Email me a reset link')}
                        </Button>
                    </>
                )}
            </Form>
        </AuthPage>
    );
}

import { Form, Link } from '@inertiajs/react';
import PasswordInput from '@/components/password-input';
import { AuthLink, AuthPage } from '@/components/patterns/auth-page';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { __ } from '@/lib/i18n';
import { store } from '@/routes/password/confirm';

/**
 * Confirm your password before a sensitive step: the lead names the page
 * it guards when there is one, and Cancel goes back where the person came
 * from.
 */
export default function ConfirmPassword({
    guards,
    cancelUrl,
}: {
    /** The page the password is asked for, such as "Security"; null for another. */
    guards: string | null;
    cancelUrl: string;
}) {
    return (
        <AuthPage
            title={__('Confirm your password')}
            lead={
                guards === null
                    ? __(
                          'Teme asks for your password again before sensitive steps.',
                      )
                    : __(
                          'You’re about to open :page. Teme asks for your password again before sensitive steps.',
                          { page: guards },
                      )
            }
            links={
                <AuthLink asChild>
                    <Link href={cancelUrl} data-test="confirm-password-cancel">
                        {__('Cancel')}
                    </Link>
                </AuthLink>
            }
        >
            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="grid gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        <Field
                            htmlFor="password"
                            label={__('Password')}
                            error={errors.password}
                        >
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="current-password"
                                autoFocus
                            />
                        </Field>

                        <Button
                            type="submit"
                            variant="primary"
                            className="w-full"
                            loading={processing}
                            data-test="confirm-password-button"
                        >
                            {__('Confirm password')}
                        </Button>
                    </>
                )}
            </Form>
        </AuthPage>
    );
}

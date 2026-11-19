import { Form } from '@inertiajs/react';
import InvitationController from '@/actions/App/Http/Admin/Auth/InvitationController';
import PasswordInput from '@/components/password-input';
import { AuthPage } from '@/components/patterns/auth-page';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { __ } from '@/lib/i18n';
import { passwordMinimum } from '@/lib/password';

type Props = {
    token: string;
    email: string;
    passwordRules: string;
    /** What the invitation offers, while its link still works. */
    invitation: {
        name: string;
        role: string | null;
        invitedBy: string | null;
        site: string | null;
    } | null;
};

/** One line of the invitation's summary. */
function Fact({ term, value }: { term: string; value: string }) {
    return (
        <div className="flex flex-wrap gap-x-3">
            <dt className="text-small text-ink-2 w-28 shrink-0">{term}</dt>
            <dd className="text-body text-ink min-w-0 break-words">{value}</dd>
        </div>
    );
}

/**
 * You're invited: who invited you, as what, and to which site; then your
 * name and a password, and "Join" the site.
 */
export default function AcceptInvitation({
    token,
    email,
    passwordRules,
    invitation,
}: Props) {
    const minimum = passwordMinimum(passwordRules);
    const site = invitation?.site ?? null;
    const lead =
        invitation?.invitedBy && site
            ? __(
                  ':name invited you to write for :site. Choose a password to finish.',
                  { name: invitation.invitedBy, site },
              )
            : site
              ? __(
                    'You were invited to write for :site. Choose a password to finish.',
                    { site },
                )
              : __('Choose a password to finish setting up your account.');

    return (
        <AuthPage title={__('You’re invited')} lead={lead}>
            {invitation !== null && (
                <dl
                    data-test="invitation-summary"
                    className="bg-surface-2 grid gap-2 rounded-md p-4"
                >
                    {invitation.invitedBy !== null && (
                        <Fact
                            term={__('Invited by')}
                            value={invitation.invitedBy}
                        />
                    )}
                    {invitation.role !== null && (
                        <Fact term={__('Your role')} value={invitation.role} />
                    )}
                    <Fact term={__('Email')} value={email} />
                </dl>
            )}
            <Form
                {...InvitationController.store.form()}
                transform={(data) => ({ ...data, token, email })}
                resetOnSuccess={['password', 'password_confirmation']}
                className="grid gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        {invitation === null && (
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
                                    value={email}
                                    readOnly
                                />
                            </Field>
                        )}
                        {invitation !== null && errors.email && (
                            <p
                                role="alert"
                                className="text-small text-danger-ink"
                            >
                                {errors.email}
                            </p>
                        )}

                        <Field
                            htmlFor="name"
                            label={__('Your name')}
                            help={__(
                                'How your name appears on the posts you write.',
                            )}
                            error={errors.name}
                        >
                            <Input
                                id="name"
                                name="name"
                                autoComplete="name"
                                defaultValue={invitation?.name ?? ''}
                            />
                        </Field>

                        <Field
                            htmlFor="password"
                            label={__('Choose a password')}
                            help={
                                minimum === null
                                    ? undefined
                                    : __('At least :count characters.', {
                                          count: minimum,
                                      })
                            }
                            error={errors.password}
                        >
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="new-password"
                                autoFocus
                                passwordrules={passwordRules}
                            />
                        </Field>

                        <Field
                            htmlFor="password_confirmation"
                            label={__('Type it again')}
                            error={errors.password_confirmation}
                        >
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                autoComplete="new-password"
                                passwordrules={passwordRules}
                            />
                        </Field>

                        <Button
                            type="submit"
                            variant="primary"
                            className="w-full"
                            loading={processing}
                            data-test="accept-invitation-button"
                        >
                            {site === null
                                ? __('Join')
                                : __('Join :site', { site })}
                        </Button>
                    </>
                )}
            </Form>
        </AuthPage>
    );
}

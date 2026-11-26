import { Form, Head, Link, setLayoutProps, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import ProfileController from '@/actions/App/Http/Admin/Account/ProfileController';
import { PictureField } from '@/components/account/picture-panel';
import InputError from '@/components/input-error';
import { FormPanel } from '@/components/patterns/form-page';
import { SaveBar } from '@/components/patterns/save-bar';
import { SettingsPage } from '@/components/patterns/settings-page';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Notice } from '@/components/ui/notice';
import { Textarea } from '@/components/ui/textarea';
import { __ } from '@/lib/i18n';
import { send } from '@/routes/verification';

/** One fact the site sets, with the line saying who changes it. */
function Fact({ term, children }: { term: string; children: ReactNode }) {
    return (
        <div className="grid min-w-0 gap-0.5 sm:flex sm:gap-3">
            <dt className="text-small text-ink-2 sm:w-40 sm:shrink-0">
                {term}
            </dt>
            <dd className="text-body text-ink grid min-w-0">{children}</dd>
        </div>
    );
}

/**
 * Your account › Profile: your picture, name, email address and biography,
 * saved together, then what the site sets for you: your role and your
 * author page.
 */
export default function Profile({
    mustVerifyEmail,
    status,
    bio,
    publicProfile,
    authorPage,
}: {
    mustVerifyEmail: boolean;
    status?: string;
    /** The bio shown on the person's author page and under their articles. */
    bio: string;
    /** What the person chose to show on their author page. */
    publicProfile: {
        role: string;
        coverage: string;
        links: { label: string; url: string }[];
    };
    /** Your author page on the site: its address and its path to show. */
    authorPage: { url: string; path: string } | null;
}) {
    const { auth } = usePage().props;
    const [email, setEmail] = useState(auth.user.email);
    const changingEmail = email.trim() !== auth.user.email;
    const unverified = mustVerifyEmail && !auth.user.emailVerified;
    const title = __('Profile');
    const description = __('Your name, email address and biography.');

    setLayoutProps({
        title,
        description,
    });

    return (
        <>
            <Head title={title} />

            <SettingsPage title={title} description={description} titleAs="div">
                {unverified && (
                    <Notice
                        tone="warn"
                        role="status"
                        data-test="email-unverified"
                        action={
                            <Button variant="neutral" size="sm" asChild>
                                <Link href={send()} as="button">
                                    {__('Send it again')}
                                </Link>
                            </Button>
                        }
                    >
                        {status === 'verification-link-sent'
                            ? __('Sent. Check your email for the new link.')
                            : __(
                                  'Your email address isn’t verified yet. We sent a link to :email.',
                                  { email: auth.user.email },
                              )}
                    </Notice>
                )}

                <Form
                    {...ProfileController.update.form()}
                    options={{ preserveScroll: true }}
                    setDefaultsOnSuccess
                >
                    {({ processing, errors, isDirty, reset }) => (
                        <>
                            <FormPanel title={__('Your details')}>
                                <PictureField />

                                <Field
                                    htmlFor="name"
                                    label={__('Name')}
                                    help={__(
                                        'Shown on your posts and on your author page.',
                                    )}
                                    error={errors.name}
                                >
                                    <Input
                                        id="name"
                                        defaultValue={auth.user.name}
                                        name="name"
                                        required
                                        autoComplete="name"
                                    />
                                </Field>

                                <Field
                                    htmlFor="email"
                                    label={__('Email address')}
                                    help={
                                        changingEmail
                                            ? __(
                                                  'Changing it sends a link to the new address. It becomes your sign-in address once you open the link.',
                                              )
                                            : __(
                                                  'You sign in with it, and notices arrive at it.',
                                              )
                                    }
                                    error={errors.email}
                                >
                                    <Input
                                        id="email"
                                        type="email"
                                        defaultValue={auth.user.email}
                                        name="email"
                                        required
                                        autoComplete="username"
                                        onChange={(event) =>
                                            setEmail(event.target.value)
                                        }
                                    />
                                </Field>

                                <Field
                                    htmlFor="bio"
                                    label={__('Short biography')}
                                    help={__(
                                        'Shown on your author page and under your articles. Up to 1,000 characters.',
                                    )}
                                    error={errors.bio}
                                >
                                    <Textarea
                                        id="bio"
                                        name="bio"
                                        defaultValue={bio}
                                        maxLength={1000}
                                        rows={4}
                                    />
                                </Field>
                            </FormPanel>

                            <FormPanel
                                title={__('On your author page')}
                                description={__(
                                    'Each one is shown only when you fill it in. Nothing else about your account is shown.',
                                )}
                            >
                                <Field
                                    htmlFor="public-role"
                                    label={__('Your title')}
                                    help={__('For example “Senior editor”.')}
                                    error={errors['public_profile.role']}
                                >
                                    <Input
                                        id="public-role"
                                        name="public_profile[role]"
                                        defaultValue={publicProfile.role}
                                        maxLength={120}
                                    />
                                </Field>
                                <Field
                                    htmlFor="public-coverage"
                                    label={__('What you cover')}
                                    error={errors['public_profile.coverage']}
                                >
                                    <Input
                                        id="public-coverage"
                                        name="public_profile[coverage]"
                                        defaultValue={publicProfile.coverage}
                                        maxLength={255}
                                    />
                                </Field>
                                <div className="grid gap-3">
                                    <p className="text-small text-ink-2 font-medium">
                                        {__('Links, up to six')}
                                    </p>
                                    {Array.from({ length: 6 }, (_, index) => (
                                        <div
                                            key={index}
                                            className="grid gap-2 sm:grid-cols-2"
                                        >
                                            <Input
                                                name={`public_profile[links][${index}][label]`}
                                                defaultValue={
                                                    publicProfile.links[index]
                                                        ?.label ?? ''
                                                }
                                                maxLength={60}
                                                aria-label={__(
                                                    'Link :number label',
                                                    { number: index + 1 },
                                                )}
                                                placeholder={__('Label')}
                                            />
                                            <Input
                                                type="url"
                                                name={`public_profile[links][${index}][url]`}
                                                defaultValue={
                                                    publicProfile.links[index]
                                                        ?.url ?? ''
                                                }
                                                maxLength={500}
                                                aria-label={__(
                                                    'Link :number address',
                                                    { number: index + 1 },
                                                )}
                                                placeholder={__(
                                                    'https://example.org',
                                                )}
                                            />
                                            <InputError
                                                message={
                                                    errors[
                                                        `public_profile.links.${index}.label`
                                                    ] ??
                                                    errors[
                                                        `public_profile.links.${index}.url`
                                                    ]
                                                }
                                            />
                                        </div>
                                    ))}
                                </div>
                            </FormPanel>

                            <FormPanel title={__('Set by the site')}>
                                <dl className="grid gap-4">
                                    <Fact term={__('Role')}>
                                        <span>{auth.user.role ?? '—'}</span>
                                        <span className="text-small text-ink-2">
                                            {__(
                                                'Only another administrator can change it, under Settings › People.',
                                            )}
                                        </span>
                                    </Fact>
                                    {authorPage !== null && (
                                        <Fact term={__('Your author page')}>
                                            <a
                                                href={authorPage.url}
                                                data-test="author-page"
                                                className="text-accent-ink justify-self-start underline-offset-2 hover:underline"
                                            >
                                                {authorPage.path}
                                            </a>
                                        </Fact>
                                    )}
                                </dl>
                            </FormPanel>

                            <SaveBar
                                dirty={isDirty}
                                processing={processing}
                                onDiscard={() => {
                                    reset();
                                    setEmail(auth.user.email);
                                }}
                                what={__('your profile')}
                                testId="update-profile-button"
                            />
                        </>
                    )}
                </Form>
            </SettingsPage>
        </>
    );
}

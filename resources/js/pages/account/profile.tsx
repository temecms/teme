import { Form, Head, Link, setLayoutProps, usePage } from '@inertiajs/react';
import ProfileController from '@/actions/App/Http/Admin/Account/ProfileController';
import { PicturePanel } from '@/components/account/picture-panel';
import { FormPanel } from '@/components/patterns/form-page';
import { SaveBar } from '@/components/patterns/save-bar';
import { SettingsPage } from '@/components/patterns/settings-page';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { __ } from '@/lib/i18n';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

/**
 * Your account › Profile: your picture, then your name, email address and
 * biography, saved together.
 */
export default function Profile({
    mustVerifyEmail,
    status,
    bio,
}: {
    mustVerifyEmail: boolean;
    status?: string;
    /** The bio shown on the person's author page and under their articles. */
    bio: string;
}) {
    const { auth } = usePage().props;

    setLayoutProps({
        breadcrumbs: [{ title: __('Profile'), href: edit() }],
    });

    return (
        <>
            <Head title={__('Profile')} />

            <SettingsPage
                title={__('Profile')}
                description={__('Your name, email address and biography.')}
            >
                <PicturePanel />

                <Form
                    {...ProfileController.update.form()}
                    options={{ preserveScroll: true }}
                    setDefaultsOnSuccess
                >
                    {({ processing, errors, isDirty, reset }) => (
                        <>
                            <FormPanel title={__('Your details')}>
                                <Field
                                    htmlFor="name"
                                    label={__('Name')}
                                    error={errors.name}
                                >
                                    <Input
                                        id="name"
                                        defaultValue={auth.user.name}
                                        name="name"
                                        required
                                        autoComplete="name"
                                        placeholder={__('Full name')}
                                    />
                                </Field>

                                <div className="grid gap-2">
                                    <Field
                                        htmlFor="email"
                                        label={__('Email address')}
                                        error={errors.email}
                                    >
                                        <Input
                                            id="email"
                                            type="email"
                                            defaultValue={auth.user.email}
                                            name="email"
                                            required
                                            autoComplete="username"
                                            placeholder={__('Email address')}
                                        />
                                    </Field>

                                    {mustVerifyEmail &&
                                        !auth.user.emailVerified && (
                                            <div className="grid gap-2">
                                                <div className="flex flex-wrap items-center gap-3">
                                                    <p className="text-small text-ink-2">
                                                        {__(
                                                            'Your email address isn’t confirmed yet.',
                                                        )}
                                                    </p>
                                                    <Button
                                                        variant="neutral"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={send()}
                                                            as="button"
                                                        >
                                                            {__(
                                                                'Resend confirmation email',
                                                            )}
                                                        </Link>
                                                    </Button>
                                                </div>

                                                {status ===
                                                    'verification-link-sent' && (
                                                    <p className="text-small text-ok-ink font-medium">
                                                        {__(
                                                            'Sent. Check your email for the new link.',
                                                        )}
                                                    </p>
                                                )}
                                            </div>
                                        )}
                                </div>

                                <Field
                                    htmlFor="bio"
                                    label={__('Bio')}
                                    help={__(
                                        'Shown on your author page and under your posts.',
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

                            <SaveBar
                                dirty={isDirty}
                                processing={processing}
                                onDiscard={() => reset()}
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

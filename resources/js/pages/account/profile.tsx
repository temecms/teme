import { Form, Head, usePage } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import ProfileController from '@/actions/App/Http/Admin/Account/ProfileController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import type { Auth } from '@/types';
import { send } from '@/routes/verification';

type PageProps = {
    auth: Auth;
};

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
    const { auth } = usePage<PageProps>().props;

    return (
        <>
            <Head title="Profile" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Profile"
                    description="Update your name and email address"
                />

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>

                                <Input
                                    id="name"
                                    aria-describedby="name-error"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.name}
                                    name="name"
                                    required
                                    autoComplete="name"
                                    placeholder="Full name"
                                />

                                <InputError
                                    id="name-error"
                                    className="mt-2"
                                    message={errors.name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>

                                <Input
                                    id="email"
                                    aria-describedby="email-error"
                                    type="email"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.email}
                                    name="email"
                                    required
                                    autoComplete="username"
                                    placeholder="Email address"
                                />

                                <InputError
                                    id="email-error"
                                    className="mt-2"
                                    message={errors.email}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="bio">Bio</Label>

                                <textarea
                                    id="bio"
                                    aria-describedby="bio-hint bio-error"
                                    name="bio"
                                    defaultValue={bio}
                                    maxLength={1000}
                                    rows={4}
                                    className="border-input bg-background focus-visible:ring-ring mt-1 block w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                />

                                <p
                                    id="bio-hint"
                                    className="text-muted-foreground text-sm"
                                >
                                    Shown on your author page and under your
                                    posts.
                                </p>
                                <InputError
                                    id="bio-error"
                                    className="mt-2"
                                    message={errors.bio}
                                />
                            </div>

                            {mustVerifyEmail && !auth.user.emailVerified && (
                                <div>
                                    <div className="-mt-4 flex flex-wrap items-center gap-3">
                                        <p className="text-muted-foreground text-sm">
                                            Your email address isn’t confirmed
                                            yet.
                                        </p>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link href={send()} as="button">
                                                Resend confirmation email
                                            </Link>
                                        </Button>
                                    </div>

                                    {status === 'verification-link-sent' && (
                                        <div className="text-success mt-2 text-sm font-medium">
                                            Sent. Check your email for the new
                                            link.
                                        </div>
                                    )}
                                </div>
                            )}

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'Profile',
            href: edit(),
        },
    ],
};

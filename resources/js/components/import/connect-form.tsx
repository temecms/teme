import { Form } from '@inertiajs/react';
import ImportSourceController from '@/actions/App/Http/Admin/Tools/Import/ImportSourceController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * Connecting a WordPress site, or connecting a forgotten one again (I-227).
 * The form never resets on an error, so what was typed stays; the
 * application password never goes through the session (I-1708).
 */
export function ConnectForm({
    address,
    userName,
    companion,
}: {
    address: string | null;
    userName: string | null;
    companion?: { version: string; releases: string | null };
}) {
    const again = address !== null;

    return (
        <section
            aria-labelledby="import-connect-heading"
            className="max-w-[640px] space-y-4"
        >
            <h2 id="import-connect-heading" className="text-base font-medium">
                {again ? 'Connect again' : 'Connect a WordPress site'}
            </h2>

            {again ? (
                <p className="text-[13px]">
                    To import from this site again, make a new application
                    password in WordPress and enter it here.
                </p>
            ) : (
                <div className="space-y-2 text-[13px]">
                    <p>First, on the WordPress site:</p>
                    <ol className="list-decimal space-y-1 pl-5">
                        <li>
                            Install and activate the Teme Companion plugin,
                            version {companion?.version} or later.
                            {companion?.releases != null && (
                                <>
                                    {' '}
                                    <a
                                        href={companion.releases}
                                        className="text-foreground underline underline-offset-4"
                                    >
                                        Download Teme Companion
                                    </a>
                                </>
                            )}
                        </li>
                        <li>
                            Open Tools › Teme site report and run it. Teme reads
                            the report to show what comes across.
                        </li>
                        <li>
                            Open Users › Profile and add an application password
                            named Teme. Copy it: WordPress shows it once.
                        </li>
                    </ol>
                </div>
            )}

            <Form
                {...ImportSourceController.connect.form()}
                className="space-y-4"
            >
                {({ processing, errors }) => (
                    <>
                        {again ? (
                            <input
                                type="hidden"
                                name="address"
                                value={address}
                            />
                        ) : (
                            <div className="grid gap-1.5">
                                <Label htmlFor="import-address">
                                    Site address
                                </Label>
                                <Input
                                    id="import-address"
                                    name="address"
                                    type="url"
                                    inputMode="url"
                                    autoComplete="url"
                                    placeholder="https://example.com"
                                    aria-describedby="import-address-hint import-address-error"
                                />
                                <p
                                    id="import-address-hint"
                                    className="text-muted-foreground text-[13px]"
                                >
                                    The address readers use, such as
                                    https://example.com.
                                </p>
                                <InputError
                                    id="import-address-error"
                                    message={errors.address}
                                />
                            </div>
                        )}

                        <div className="grid gap-1.5">
                            <Label htmlFor="import-user-name">
                                WordPress user name
                            </Label>
                            <Input
                                id="import-user-name"
                                name="user_name"
                                autoComplete="username"
                                defaultValue={userName ?? undefined}
                                aria-describedby="import-user-name-hint import-user-name-error"
                            />
                            <p
                                id="import-user-name-hint"
                                className="text-muted-foreground text-[13px]"
                            >
                                An administrator’s.
                            </p>
                            <InputError
                                id="import-user-name-error"
                                message={errors.user_name}
                            />
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="import-application-password">
                                Application password
                            </Label>
                            <PasswordInput
                                id="import-application-password"
                                name="application_password"
                                autoComplete="off"
                                aria-describedby="import-application-password-hint import-application-password-error"
                            />
                            <p
                                id="import-application-password-hint"
                                className="text-muted-foreground text-[13px]"
                            >
                                Teme keeps it encrypted until you choose Forget
                                this site, and never shows it again.
                            </p>
                            <InputError
                                id="import-application-password-error"
                                message={errors.application_password}
                            />
                        </div>

                        <Button
                            type="submit"
                            data-test="import-connect"
                            disabled={processing}
                        >
                            {processing ? 'Connecting…' : 'Connect'}
                        </Button>
                        <p className="text-muted-foreground text-[13px]">
                            Connecting can take up to two minutes if the site is
                            slow to answer.
                        </p>
                    </>
                )}
            </Form>
        </section>
    );
}

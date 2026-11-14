import { Form, Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import FirstRunController from '@/actions/App/Http/Admin/Auth/FirstRunController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthSimpleLayout from '@/layouts/auth/auth-simple-layout';
import { __ } from '@/lib/i18n';

type Props = {
    timezones: string[];
    passwordRules: string;
};

/** Settings › General's native select, as the time zone setting draws it. */
const selectClass =
    'border-edge bg-page focus-visible:ring-ring mt-1 block w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/** The browser's own zone when the list has it, as Drupal's installer fills it; else UTC. */
function browserZone(timezones: string[]): string {
    const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;

    return timezones.includes(zone) ? zone : 'UTC';
}

function Hint({ id, children }: { id: string; children: ReactNode }) {
    return (
        <p id={id} className="text-ink-2 text-[13px] text-pretty">
            {children}
        </p>
    );
}

/** The command, wherever the line has it, is drawn as code. */
const COMMAND = 'php artisan teme:setup-code';

function WithCommand({ line }: { line: string }) {
    const at = line.indexOf(COMMAND);

    if (at === -1) {
        return line;
    }

    return (
        <>
            {line.slice(0, at)}
            <code className="font-mono text-[12px]">{COMMAND}</code>
            {line.slice(at + COMMAND.length)}
        </>
    );
}

export default function SetUp({ timezones, passwordRules }: Props) {
    return (
        <>
            <Head title={__('Set up your site')} />

            <Form
                {...FirstRunController.store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                resetOnError={['password', 'password_confirmation']}
            >
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="code">{__('Setup code')}</Label>
                            <Input
                                id="code"
                                aria-invalid={errors.code ? true : undefined}
                                aria-describedby="code-hint code-error"
                                name="code"
                                autoComplete="off"
                                spellCheck={false}
                                autoCapitalize="characters"
                                autoFocus
                                required
                                className="mt-1 block w-full font-mono"
                                placeholder={__('XXXX-XXXX-XXXX-XXXX')}
                            />
                            <Hint id="code-hint">
                                <WithCommand
                                    line={__(
                                        'It’s at the end of the deploy log. Or run php artisan teme:setup-code on the server.',
                                    )}
                                />
                            </Hint>
                            <InputError id="code-error" message={errors.code} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="site_title">
                                {__('Site title')}
                            </Label>
                            <Input
                                id="site_title"
                                aria-invalid={
                                    errors.site_title ? true : undefined
                                }
                                aria-describedby="site_title-hint site_title-error"
                                name="site_title"
                                required
                                className="mt-1 block w-full"
                            />
                            <Hint id="site_title-hint">
                                {__(
                                    'You can change it later in Settings › General.',
                                )}
                            </Hint>
                            <InputError
                                id="site_title-error"
                                message={errors.site_title}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="timezone">{__('Time zone')}</Label>
                            <select
                                id="timezone"
                                aria-invalid={
                                    errors.timezone ? true : undefined
                                }
                                aria-describedby="timezone-hint timezone-error"
                                name="timezone"
                                required
                                defaultValue={browserZone(timezones)}
                                className={selectClass}
                            >
                                {timezones.map((zone) => (
                                    <option key={zone} value={zone}>
                                        {zone}
                                    </option>
                                ))}
                            </select>
                            <Hint id="timezone-hint">
                                {__('Used to show and schedule dates.')}
                            </Hint>
                            <InputError
                                id="timezone-error"
                                message={errors.timezone}
                            />
                        </div>

                        <hr className="border-line" />

                        <div className="grid gap-2">
                            <Label htmlFor="name">{__('Your name')}</Label>
                            <Input
                                id="name"
                                aria-invalid={errors.name ? true : undefined}
                                aria-describedby="name-error"
                                name="name"
                                autoComplete="name"
                                required
                                className="mt-1 block w-full"
                            />
                            <InputError id="name-error" message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="email">{__('Email address')}</Label>
                            <Input
                                id="email"
                                aria-invalid={errors.email ? true : undefined}
                                aria-describedby="email-hint email-error"
                                type="email"
                                name="email"
                                autoComplete="email"
                                required
                                className="mt-1 block w-full"
                            />
                            <Hint id="email-hint">
                                {__('Teme sends notices about the site here.')}
                            </Hint>
                            <InputError
                                id="email-error"
                                message={errors.email}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">{__('Password')}</Label>
                            <PasswordInput
                                id="password"
                                aria-invalid={
                                    errors.password ? true : undefined
                                }
                                aria-describedby="password-error"
                                name="password"
                                autoComplete="new-password"
                                required
                                className="mt-1 block w-full"
                                passwordrules={passwordRules}
                            />
                            <InputError
                                id="password-error"
                                message={errors.password}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">
                                {__('Confirm password')}
                            </Label>
                            <PasswordInput
                                id="password_confirmation"
                                aria-invalid={
                                    errors.password_confirmation
                                        ? true
                                        : undefined
                                }
                                aria-describedby="password_confirmation-error"
                                name="password_confirmation"
                                autoComplete="new-password"
                                required
                                className="mt-1 block w-full"
                                passwordrules={passwordRules}
                            />
                            <InputError
                                id="password_confirmation-error"
                                message={errors.password_confirmation}
                            />
                        </div>

                        <Button
                            type="submit"
                            className="mt-4 w-full"
                            disabled={processing}
                            data-test="set-up-button"
                        >
                            {processing && <Spinner />}
                            {__('Set up the site')}
                        </Button>
                    </div>
                )}
            </Form>
        </>
    );
}

// Its own frame, not the default one: the brand block says "Teme", since the site has no name yet.
SetUp.layout = (page: ReactNode) => (
    <AuthSimpleLayout
        title={__('Set up your site')}
        description={__(
            'Name your site and make its first administrator account.',
        )}
        brandName="Teme"
    >
        {page}
    </AuthSimpleLayout>
);

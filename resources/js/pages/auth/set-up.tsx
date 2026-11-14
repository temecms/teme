import { Form } from '@inertiajs/react';
import FirstRunController from '@/actions/App/Http/Admin/Auth/FirstRunController';
import PasswordInput from '@/components/password-input';
import { AuthPage } from '@/components/patterns/auth-page';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { SelectField } from '@/components/ui/select-field';
import { __ } from '@/lib/i18n';

type Props = {
    timezones: string[];
    passwordRules: string;
};

/** The browser's own zone when the list has it, as Drupal's installer fills it; else UTC. */
function browserZone(timezones: string[]): string {
    const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;

    return timezones.includes(zone) ? zone : 'UTC';
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
            <code className="font-mono">{COMMAND}</code>
            {line.slice(at + COMMAND.length)}
        </>
    );
}

export default function SetUp({ timezones, passwordRules }: Props) {
    return (
        <AuthPage
            brand="teme"
            title={__('Set up your site')}
            lead={__(
                'Name your site and make its first administrator account.',
            )}
        >
            <Form
                {...FirstRunController.store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                resetOnError={['password', 'password_confirmation']}
                className="grid gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        <Field
                            htmlFor="code"
                            label={__('Setup code')}
                            help={
                                <WithCommand
                                    line={__(
                                        'It’s at the end of the deploy log. Or run php artisan teme:setup-code on the server.',
                                    )}
                                />
                            }
                            error={errors.code}
                        >
                            <Input
                                id="code"
                                name="code"
                                autoComplete="off"
                                spellCheck={false}
                                autoCapitalize="characters"
                                autoFocus
                                required
                                className="font-mono"
                                placeholder={__('XXXX-XXXX-XXXX-XXXX')}
                            />
                        </Field>

                        <Field
                            htmlFor="site_title"
                            label={__('Site title')}
                            help={__(
                                'You can change it later in Settings › General.',
                            )}
                            error={errors.site_title}
                        >
                            <Input id="site_title" name="site_title" required />
                        </Field>

                        <Field
                            htmlFor="timezone"
                            label={__('Time zone')}
                            help={__('Used to show and schedule dates.')}
                            error={errors.timezone}
                        >
                            <SelectField
                                id="timezone"
                                name="timezone"
                                defaultValue={browserZone(timezones)}
                                options={timezones.map((zone) => ({
                                    value: zone,
                                    label: zone,
                                }))}
                            />
                        </Field>

                        <hr className="border-line" />

                        <Field
                            htmlFor="name"
                            label={__('Your name')}
                            error={errors.name}
                        >
                            <Input
                                id="name"
                                name="name"
                                autoComplete="name"
                                required
                            />
                        </Field>

                        <Field
                            htmlFor="email"
                            label={__('Email address')}
                            help={__('Teme sends notices about the site here.')}
                            error={errors.email}
                        >
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                autoComplete="email"
                                required
                            />
                        </Field>

                        <Field
                            htmlFor="password"
                            label={__('Password')}
                            error={errors.password}
                        >
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="new-password"
                                required
                                passwordrules={passwordRules}
                            />
                        </Field>

                        <Field
                            htmlFor="password_confirmation"
                            label={__('Confirm password')}
                            error={errors.password_confirmation}
                        >
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                autoComplete="new-password"
                                required
                                passwordrules={passwordRules}
                            />
                        </Field>

                        <Button
                            type="submit"
                            variant="primary"
                            className="w-full"
                            loading={processing}
                            data-test="set-up-button"
                        >
                            {__('Set up the site')}
                        </Button>
                    </>
                )}
            </Form>
        </AuthPage>
    );
}

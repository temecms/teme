import { Form, Head, Link, setLayoutProps, usePage } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import NotificationsController from '@/actions/App/Http/Admin/Account/NotificationsController';
import InputError from '@/components/input-error';
import { FormPanel } from '@/components/patterns/form-page';
import { SaveBar } from '@/components/patterns/save-bar';
import { SettingsPage } from '@/components/patterns/settings-page';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { PluginMark } from '@/components/ui/plugin-mark';
import { __ } from '@/lib/i18n';
import { edit as editNotifications } from '@/routes/notifications';

type Notice = {
    /** The preference key: `notifications.` and the kind's key. */
    key: string;
    /** The choice as this person is worded it. */
    label: string;
    /** The channels the kind goes by, each with whether this person keeps it. */
    channels: {
        key: string;
        label: string;
        on: boolean;
    }[];
    /** Whether the site has turned it off for everyone. */
    disabled: boolean;
    /** The line shown for a kind switched off for everyone, already translated. */
    switchedOff: string | null;
};

type Props = {
    /** Every kind of core's own this person may turn off, from the registry; [] when there is none. */
    notices: Notice[];
    /** Each enabled plugin's kinds this person may turn off, under the plugin's own name. */
    pluginNotices: {
        /** The plugin's Composer name, which keys its group. */
        name: string;
        plugin: string;
        notices: Notice[];
    }[];
    /** The inbox page. */
    inboxHref: string;
};

/**
 * Account › Notifications (I-691): what reaches the inbox, and every notice
 * a person turns off for themselves, listed from the registry: core's own,
 * then each plugin's under its name (docs/plans/plugin-sockets-3.md).
 */
export default function Notifications({
    notices,
    pluginNotices,
    inboxHref,
}: Props) {
    const { auth } = usePage().props;
    const title = __('Notifications');
    const description = __(
        'What reaches your inbox, and which emails you receive.',
    );
    // The emails that protect the account or the site, sent whatever is chosen above.
    const alwaysSent: [string, string][] = [
        [
            __('Password changed'),
            __(
                'Always sent. It tells you if someone else changes your password.',
            ),
        ],
        ...(auth.user.capabilities.includes('updates.manage')
            ? ([
                  [
                      __('Updates of Teme'),
                      __(
                          'Always sent to administrators, so a new version is never missed.',
                      ),
                  ],
              ] as [string, string][])
            : []),
        ...(auth.user.capabilities.includes('backups.alert')
            ? ([
                  [
                      __('Backup alerts'),
                      __(
                          'Always sent to administrators, so a failed backup is never missed.',
                      ),
                  ],
              ] as [string, string][])
            : []),
    ];

    setLayoutProps({
        breadcrumbs: [{ title, href: editNotifications() }],
        title,
        description,
    });

    return (
        <>
            <Head title={__('Notifications')} />

            <SettingsPage title={title} description={description} titleAs="div">
                <FormPanel>
                    <div className="flex flex-wrap items-center gap-4">
                        <div className="grid min-w-0 flex-1 basis-60 gap-1">
                            <h2 className="text-lead text-ink font-semibold">
                                {__('Inbox')}
                            </h2>
                            <p className="text-small text-ink-2">
                                {__(
                                    'Results of background work you start, such as an import, arrive in your inbox. The bell at the top of every screen counts the ones you haven’t read. Each is kept for 90 days.',
                                )}
                            </p>
                        </div>
                        <Button variant="neutral" asChild>
                            <Link href={inboxHref}>{__('Open inbox')}</Link>
                        </Button>
                    </div>
                </FormPanel>

                {notices.length === 0 && pluginNotices.length === 0 ? (
                    <FormPanel title={__('Email')}>
                        <p className="text-body text-ink-2">
                            {__('There are no emails to choose here.')}
                        </p>
                    </FormPanel>
                ) : (
                    <Form
                        {...NotificationsController.update.form()}
                        options={{ preserveScroll: true }}
                        setDefaultsOnSuccess
                    >
                        {({ processing, errors, isDirty, reset }) => (
                            <>
                                {notices.length > 0 && (
                                    <FormPanel
                                        title={__('Email')}
                                        description={
                                            <span id="notifications-hint">
                                                {__(
                                                    'Here you turn these off for yourself. Some can also be turned off for everyone, in Settings.',
                                                )}
                                            </span>
                                        }
                                    >
                                        <Choices
                                            legend={__('Email')}
                                            hint="notifications-hint"
                                            notices={notices}
                                            errors={errors}
                                        />
                                    </FormPanel>
                                )}

                                {/* Each plugin's kinds after core's, named once, by the plugin's own name with its mark. */}
                                {pluginNotices.map((group) => (
                                    <FormPanel key={group.name}>
                                        <h2 data-test="plugin-notices">
                                            <PluginMark
                                                name={group.plugin}
                                                className="text-lead text-ink gap-2 font-semibold"
                                            />
                                        </h2>
                                        <Choices
                                            legend={group.plugin}
                                            notices={group.notices}
                                            errors={errors}
                                        />
                                    </FormPanel>
                                ))}

                                {/* A form of disabled rows sends nothing to save. */}
                                {[
                                    ...notices,
                                    ...pluginNotices.flatMap(
                                        (group) => group.notices,
                                    ),
                                ].some((notice) => !notice.disabled) && (
                                    <SaveBar
                                        dirty={isDirty}
                                        processing={processing}
                                        onDiscard={() => reset()}
                                        saveLabel={__('Save notifications')}
                                        what={__('your notifications')}
                                    />
                                )}
                            </>
                        )}
                    </Form>
                )}

                <FormPanel
                    title={__('Always sent')}
                    description={__(
                        'These can’t be turned off, because each protects your account or the site.',
                    )}
                >
                    <ul className="grid gap-3" data-test="always-sent">
                        {alwaysSent.map(([name, why]) => (
                            <li key={name} className="flex items-start gap-3">
                                <span className="text-body flex h-lh shrink-0 items-center">
                                    <Lock
                                        aria-hidden="true"
                                        className="text-ink-3 size-4"
                                    />
                                </span>
                                <span className="grid gap-0.5">
                                    <span className="text-body text-ink font-medium">
                                        {name}
                                    </span>
                                    <span className="text-small text-ink-2">
                                        {why}
                                    </span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </FormPanel>
            </SettingsPage>
        </>
    );
}

/**
 * One kind of notice: its channels' switches, or one switch for a kind sent
 * one way, and why it is off when the site turned it off for everyone.
 */
function NoticeRow({
    notice,
    hint,
    error,
}: {
    notice: Notice;
    /** The id of the line that explains these switches, when the page shows it. */
    hint?: string;
    error?: string;
}) {
    return (
        <div className="grid gap-1">
            <div className="flex flex-wrap items-center gap-2">
                {/* A disabled row sends nothing, so the person's choice is kept; the empty item stands for no channel. */}
                {!notice.disabled && (
                    <input
                        type="hidden"
                        name={`preferences[${notice.key}][]`}
                        value=""
                    />
                )}
                {notice.channels.length === 1 ? (
                    <>
                        <Checkbox
                            id={`pref-${notice.key}`}
                            name={`preferences[${notice.key}][]`}
                            value={notice.channels[0].key}
                            defaultChecked={notice.channels[0].on}
                            disabled={notice.disabled}
                            aria-describedby={
                                notice.disabled
                                    ? [hint, `pref-${notice.key}-off`]
                                          .filter(Boolean)
                                          .join(' ')
                                    : hint
                            }
                        />
                        <Label
                            htmlFor={`pref-${notice.key}`}
                            className="text-body text-ink"
                        >
                            {notice.label}
                        </Label>
                    </>
                ) : (
                    <>
                        <span className="text-body">{notice.label}</span>
                        {notice.channels.map((channel) => (
                            <div
                                key={channel.key}
                                className="flex items-center gap-2"
                            >
                                <Checkbox
                                    id={`pref-${notice.key}-${channel.key}`}
                                    name={`preferences[${notice.key}][]`}
                                    value={channel.key}
                                    defaultChecked={channel.on}
                                    disabled={notice.disabled}
                                    aria-describedby={
                                        notice.disabled
                                            ? [hint, `pref-${notice.key}-off`]
                                                  .filter(Boolean)
                                                  .join(' ')
                                            : hint
                                    }
                                />
                                <Label
                                    htmlFor={`pref-${notice.key}-${channel.key}`}
                                    className="text-body text-ink"
                                >
                                    {channel.label}
                                </Label>
                            </div>
                        ))}
                    </>
                )}
            </div>
            {notice.disabled && notice.switchedOff !== null && (
                <p
                    id={`pref-${notice.key}-off`}
                    className="text-caption text-ink-3"
                >
                    {notice.switchedOff}
                </p>
            )}
            <InputError message={error} />
        </div>
    );
}

/**
 * A group's switches in one fieldset. A checkbox tells its hidden input with
 * a click, not a change, so the form is told here and its save bar shows.
 */
function Choices({
    legend,
    hint,
    notices,
    errors,
}: {
    legend: string;
    hint?: string;
    notices: Notice[];
    errors: Record<string, string>;
}) {
    return (
        <fieldset
            aria-describedby={hint}
            className="grid gap-3"
            onClick={(event) => {
                if (event.target instanceof HTMLInputElement) {
                    event.currentTarget.dispatchEvent(
                        new Event('change', { bubbles: true }),
                    );
                }
            }}
        >
            <legend className="sr-only">{legend}</legend>
            {notices.map((notice) => (
                <NoticeRow
                    key={notice.key}
                    notice={notice}
                    hint={hint}
                    error={errors[`preferences.${notice.key}`]}
                />
            ))}
        </fieldset>
    );
}

import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import NotificationsController from '@/actions/App/Http/Admin/Account/NotificationsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
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
    /** Every kind of notice this person may turn off, from the registry; [] when there is none. */
    notices: Notice[];
    /** The inbox page. */
    inboxHref: string;
};

/**
 * Account › Notifications (I-691): what reaches the inbox, and every notice
 * a person turns off for themselves, listed from the registry.
 */
export default function Notifications({ notices, inboxHref }: Props) {
    setLayoutProps({
        breadcrumbs: [
            {
                title: __('Notifications'),
                href: editNotifications(),
            },
        ],
    });

    return (
        <>
            <Head title={__('Notifications')} />

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title={__('Inbox')}
                        description={__(
                            'Results of background work you start, such as an import, arrive in your inbox. The bell at the top of every screen counts the ones you haven’t read. Each is kept for 90 days.',
                        )}
                    />
                    <Button variant="outline" size="sm" asChild>
                        <Link href={inboxHref}>{__('Open inbox')}</Link>
                    </Button>
                </div>

                {notices.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        {__('There are no emails to choose here.')}
                    </p>
                ) : (
                    <Form
                        {...NotificationsController.update.form()}
                        options={{ preserveScroll: true }}
                        className="border-border space-y-6 border-t pt-6"
                    >
                        {({ processing, errors }) => (
                            <>
                                <fieldset className="grid gap-3">
                                    <legend className="mb-2 text-sm font-medium">
                                        {__('Email')}
                                    </legend>
                                    <p
                                        id="notifications-hint"
                                        className="text-muted-foreground text-sm"
                                    >
                                        {__(
                                            'Here you turn these off for yourself. Some can also be turned off for everyone, in Settings.',
                                        )}
                                    </p>
                                    {notices.map((notice) => (
                                        <div
                                            key={notice.key}
                                            className="grid gap-1"
                                        >
                                            <div className="flex flex-wrap items-center gap-2">
                                                {/* A disabled row sends nothing, so the person's choice is kept; the empty item stands for no channel. */}
                                                {!notice.disabled && (
                                                    <input
                                                        type="hidden"
                                                        name={`preferences[${notice.key}][]`}
                                                        value=""
                                                    />
                                                )}
                                                {notice.channels.length ===
                                                1 ? (
                                                    <>
                                                        <Checkbox
                                                            id={`pref-${notice.key}`}
                                                            name={`preferences[${notice.key}][]`}
                                                            value={
                                                                notice
                                                                    .channels[0]
                                                                    .key
                                                            }
                                                            defaultChecked={
                                                                notice
                                                                    .channels[0]
                                                                    .on
                                                            }
                                                            disabled={
                                                                notice.disabled
                                                            }
                                                            aria-describedby={
                                                                notice.disabled
                                                                    ? `notifications-hint pref-${notice.key}-off`
                                                                    : 'notifications-hint'
                                                            }
                                                        />
                                                        <Label
                                                            htmlFor={`pref-${notice.key}`}
                                                        >
                                                            {notice.label}
                                                        </Label>
                                                    </>
                                                ) : (
                                                    <>
                                                        <span className="text-sm">
                                                            {notice.label}
                                                        </span>
                                                        {notice.channels.map(
                                                            (channel) => (
                                                                <div
                                                                    key={
                                                                        channel.key
                                                                    }
                                                                    className="flex items-center gap-2"
                                                                >
                                                                    <Checkbox
                                                                        id={`pref-${notice.key}-${channel.key}`}
                                                                        name={`preferences[${notice.key}][]`}
                                                                        value={
                                                                            channel.key
                                                                        }
                                                                        defaultChecked={
                                                                            channel.on
                                                                        }
                                                                        disabled={
                                                                            notice.disabled
                                                                        }
                                                                        aria-describedby={
                                                                            notice.disabled
                                                                                ? `notifications-hint pref-${notice.key}-off`
                                                                                : 'notifications-hint'
                                                                        }
                                                                    />
                                                                    <Label
                                                                        htmlFor={`pref-${notice.key}-${channel.key}`}
                                                                    >
                                                                        {
                                                                            channel.label
                                                                        }
                                                                    </Label>
                                                                </div>
                                                            ),
                                                        )}
                                                    </>
                                                )}
                                            </div>
                                            {notice.disabled &&
                                                notice.switchedOff !== null && (
                                                    <p
                                                        id={`pref-${notice.key}-off`}
                                                        className="text-muted-foreground text-sm"
                                                    >
                                                        {notice.switchedOff}
                                                    </p>
                                                )}
                                            <InputError
                                                message={
                                                    errors[
                                                        `preferences.${notice.key}`
                                                    ]
                                                }
                                            />
                                        </div>
                                    ))}
                                </fieldset>

                                {/* A form of disabled rows sends nothing to save. */}
                                {notices.some((notice) => !notice.disabled) && (
                                    <Button type="submit" disabled={processing}>
                                        {__('Save notifications')}
                                    </Button>
                                )}
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}

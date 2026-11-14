import { Head, setLayoutProps } from '@inertiajs/react';
import Heading from '@/components/heading';
import { __ } from '@/lib/i18n';
import { show as showSettings } from '@/routes/settings';

/**
 * Settings › Languages, a placeholder (I-3373): the site's languages are
 * added, changed and removed by their Operations, and this screen's form
 * comes in a later release.
 */
export default function LanguagesSettings() {
    setLayoutProps({
        breadcrumbs: [
            {
                title: __('Languages'),
                href: showSettings({ group: 'languages' }),
            },
        ],
    });

    return (
        <>
            <Head title={__('Languages')} />

            <Heading level={1} title={__('Languages')} />
        </>
    );
}

// IBM Plex, bundled by Vite into the build (I-196). No font service is
// contacted. Each import carries a unicode-range per alphabet, so a browser
// fetches Latin Extended only when a page has a character that needs it.
import '@fontsource-variable/ibm-plex-sans/wght.css';
import '@fontsource/ibm-plex-serif/400.css';
import '@fontsource/ibm-plex-serif/400-italic.css';
import '@fontsource/ibm-plex-serif/600.css';
import '@fontsource/ibm-plex-mono/400.css';
import '@fontsource/ibm-plex-mono/500.css';
import { createInertiaApp, router } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AccountLayout from '@/layouts/account/layout';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { installHistoryGuard } from '@/lib/history-guard';
import { setDateContext } from '@/lib/dates';
import { setWords } from '@/lib/i18n';

const appName = import.meta.env.VITE_APP_NAME || 'Teme';

// Before Inertia's own popstate listener, so the editor can hold Back.
installHistoryGuard();

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('auth/'):
                // AuthPage is each sign-in page's own frame.
                return null;
            case name.startsWith('account/'):
                return [AppLayout, AccountLayout];
            case name === 'settings/index':
                // The Settings hub is a hub, outside the Settings frame.
                return AppLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app, { page }) {
        // The first page's words, zone and language, before anything is drawn.
        setWords(page.props.i18n);
        setDateContext(page.props.siteTimezone, page.props.i18n?.locale);

        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        // Inertia writes this into the progress bar's CSS, so a token works.
        color: 'var(--accent)',
    },
});

// Every later page's words, zone and language, before it is drawn: the same while the catalogue is unchanged.
router.on('beforeUpdate', (event) => {
    setWords(event.detail.page.props.i18n);
    setDateContext(
        event.detail.page.props.siteTimezone,
        event.detail.page.props.i18n?.locale,
    );
});

initializeTheme();

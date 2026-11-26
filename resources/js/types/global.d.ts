import type { Words } from '@/lib/i18n';
import type { Auth } from '@/types/auth';
import type { ContentTypeInfo } from '@/types/content';
import type { HeaderCounts } from '@/types/jobs';
import type { AdminNavEntry } from '@/types/navigation';
import type { Preferences } from '@/types/preferences';
import type { SettingsNavItem } from '@/types/settings';
import type { StatusBanner } from '@/types/status';
import type { TaxonomyInfo } from '@/types/terms';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            nav: AdminNavEntry[];
            settingsNav: SettingsNavItem[];
            /** Every status check's banner this person may act on, the most pressing first (I-3397). */
            siteStatus: StatusBanner[];
            /** Whether this person may act on any status check (Home's Site status panel). */
            siteStatusCanAct: boolean;
            pageCacheOn: boolean;
            /** The header's jobs button and bell (I-104); null for anyone not signed in and verified. */
            headerCounts: HeaderCounts | null;
            contentTypes: ContentTypeInfo[];
            taxonomies: TaxonomyInfo[];
            /** The signed-in person's preferences; empty when no one is signed in. */
            preferences: Preferences;
            sidebarOpen: boolean;
            /** The admin's words (@/lib/i18n), sent once a visit and remembered after. */
            i18n: Words;
            /** The site's zone, as an IANA name: every date the admin writes is on its clock (@/lib/dates). */
            siteTimezone: string;
            [key: string]: unknown;
        };
    }
}

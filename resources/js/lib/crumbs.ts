import { usePage } from '@inertiajs/react';
import { __ } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';

/**
 * The first crumb of a screen in the menu's Content or Site section
 * (docs/architecture/design-system.md, section 4.31): the section's name,
 * leading to its first entry, whose crumb opens the section's switcher.
 */
export function useSectionCrumb(section: 'content' | 'site'): BreadcrumbItem {
    const { nav } = usePage().props;
    const first = nav.find(
        (entry) => entry.section === section && !entry.hidden && !entry.soon,
    );

    return {
        title: section === 'content' ? __('Content') : __('Site'),
        href: first?.href ?? '',
    };
}

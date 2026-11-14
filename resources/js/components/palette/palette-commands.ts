import { router } from '@inertiajs/react';
import ContentListController from '@/actions/App/Http/Admin/Contents/ContentListController';
import {
    ALT_TEXT_EVENT,
    altTextTarget,
} from '@/components/media/alt-text-target';
import type { AdminNavEntry, ContentTypeInfo } from '@/types';
import type { PaletteCommand } from './palette-context';

/** A screen Go to offers: an entry, a child or a hub's page. */
export type GoToTarget = {
    id: string;
    label: string;
    icon: string;
    href: string;
    /** Set for an entry the person hid from their menu, or one on another site. */
    description?: string;
    /** A plugin's link to another site, opened in a new tab (I-3353). */
    external?: boolean;
    order: number;
    keywords: string[];
};

/**
 * Every screen the menu offers, from the shared `nav` prop — the same list
 * the sidebar draws, so the two never disagree, and a screen the person
 * may not open is never offered: each entry, then its children and a
 * hub's pages, as "Posts › Categories" and "Settings › General" (I-220).
 */
export function goToTargets(nav: AdminNavEntry[]): GoToTarget[] {
    // A planned entry opens nothing, so Go to never offers it (I-3408).
    return nav
        .filter((entry) => entry.soon !== true)
        .flatMap((entry, index) => [
            {
                id: `go.${entry.key}`,
                label: entry.label,
                icon: entry.icon,
                href: entry.href,
                // A hidden entry is out of the sidebar only; here it says so.
                ...(entry.hidden
                    ? { description: 'Hidden from your menu' }
                    : {}),
                order: index * 1000,
                keywords: [entry.key],
            },
            ...entry.children
                .filter((child) => child.soon !== true)
                .map((child, position) => ({
                    id: `go.${child.key}`,
                    label: `${entry.label} › ${child.label}`,
                    icon: child.icon,
                    href: child.href,
                    order: index * 1000 + 1 + position,
                    keywords: [child.key, entry.key],
                })),
            ...entry.screens.map((screen, position) => ({
                id: `go.${entry.key}.${screen.key}`,
                label: `${entry.label} › ${screen.label}`,
                icon: screen.icon,
                href: screen.href,
                ...(screen.external === true
                    ? { description: 'Opens in a new tab', external: true }
                    : {}),
                order: index * 1000 + 500 + position,
                keywords: [screen.key, entry.key],
            })),
        ]);
}

/** Go to: each target, visited when chosen. */
export function goToCommands(nav: AdminNavEntry[]): PaletteCommand[] {
    return goToTargets(nav).map((target) => ({
        id: target.id,
        owner: 'core',
        label: target.label,
        ...(target.description === undefined
            ? {}
            : { description: target.description }),
        icon: target.icon,
        group: 'Go to',
        order: target.order,
        keywords: target.keywords,
        run: () =>
            target.external === true
                ? window.open(target.href, '_blank', 'noopener,noreferrer')
                : router.visit(target.href),
    }));
}

/**
 * Do: one New {singular} per content type the person may create, in the
 * registry's order — the palette never names a type — and Upload files for
 * someone the menu offers Media to.
 */
export function doCommands(
    contentTypes: ContentTypeInfo[],
    nav: AdminNavEntry[],
): PaletteCommand[] {
    const created = contentTypes
        .filter((type) => type.canCreate)
        .map((type, index) => ({
            id: `new.${type.key}`,
            owner: 'core',
            label: `New ${type.singular}`,
            description: `Start a new ${type.singular.toLowerCase()} in the editor`,
            icon: type.icon,
            group: 'Do',
            order: index,
            keywords: ['add', 'create', 'write', type.plural.toLowerCase()],
            // Opens a new draft in the editor, as the list's Add new does (I-425).
            run: () =>
                router.post(
                    ContentListController.store.url({ type: type.segment }),
                ),
        }));

    const media = nav.find((entry) => entry.key === 'media');

    return [
        ...created,
        ...(media === undefined
            ? []
            : [
                  {
                      id: 'upload',
                      owner: 'core',
                      label: 'Upload files',
                      description: 'Add images and documents to the library',
                      icon: media.icon,
                      group: 'Do',
                      order: 100,
                      keywords: ['media', 'image', 'add', 'library'],
                      run: () => router.visit(media.href),
                  },
              ]),
    ];
}

/**
 * AI: each task's shortcut on the screen it works on (I-321), offered only
 * while the screen's `ai` prop offers that task (`context.aiTasks`). The
 * palette draws them as their own section after Do, and only when one is
 * offered (I-1318). A shortcut on the editor asks with the window event
 * teme:assistant, whose detail is the assistant's AskOptions.
 */
export function aiCommands(
    // Read so the palette redraws this section when a screen registers commands, as an open image's details do.
    _registered: PaletteCommand[][] = [],
): PaletteCommand[] {
    return [
        {
            id: 'ai.alt-text',
            owner: 'core',
            label: 'Suggest alt text for this image',
            description: 'Ask AI to describe the image whose details are open',
            group: 'AI',
            order: 0,
            keywords: ['ai', 'alt', 'describe', 'image', 'accessibility'],
            // Only while an image's details offer it, and for the one opened last (I-321).
            when: () => altTextTarget() !== null,
            run: () =>
                window.dispatchEvent(
                    new CustomEvent(ALT_TEXT_EVENT, {
                        detail: { target: altTextTarget() },
                    }),
                ),
        },
        {
            id: 'ai.metadata',
            owner: 'core',
            label: 'Suggest metadata for this post',
            description:
                'An SEO title, meta description, excerpt and social text, each under its field',
            group: 'AI',
            order: 200,
            keywords: ['seo', 'meta', 'description', 'excerpt', 'social'],
            when: (context) =>
                context.contentId !== null &&
                context.aiTasks.includes('metadata'),
            run: () =>
                window.dispatchEvent(
                    new CustomEvent('teme:assistant', {
                        detail: {
                            task: 'metadata',
                            scope: 'Metadata: search and social text, and the excerpt',
                        },
                    }),
                ),
        },
    ];
}

/** Whether a command matches what is typed: every word of it in its label or its keywords. */
export function matches(command: PaletteCommand, query: string): boolean {
    const label = command.label.toLowerCase();
    const keywords = command.keywords ?? [];

    return query
        .trim()
        .toLowerCase()
        .split(/\s+/)
        .filter((word) => word !== '')
        .every(
            (word) =>
                label.includes(word) ||
                keywords.some((keyword) => keyword.includes(word)),
        );
}

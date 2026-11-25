import { router } from '@inertiajs/react';
import type { VisitOptions } from '@inertiajs/core';
import { index as regionsIndex } from '@/routes/regions';

/** How long a region's visit is kept, so going back to a region just opened is instant. */
const KEPT_FOR = '5m';

/** How long the pointer rests on a region before its visit is fetched, as Inertia's own links wait. */
export const HOVER_MS = 75;

/** The cache tag every kept visit of one region carries. */
function tagOf(key: string): string {
    return `region:${key}`;
}

/** The address of a region's visit: the same page, the region in the query. */
function addressOf(key: string): string {
    return regionsIndex({ query: { region: key } }).url;
}

/**
 * The options of a region's visit. Prefetching and choosing share them, so
 * Inertia finds the kept visit when the region is chosen.
 */
const VISIT: VisitOptions = {
    preserveScroll: true,
    preserveState: true,
    only: ['chosen'],
};

/** Fetches a region's visit ahead, when the pointer rests on its row or the row takes focus, and keeps it. */
export function prefetchRegion(key: string): void {
    router.prefetch(addressOf(key), VISIT, {
        cacheFor: KEPT_FOR,
        cacheTags: [tagOf(key)],
    });
}

/**
 * Shows a region at once from its kept visit, or asks the server when there
 * is none and keeps what came. Inertia registers a prefetch a tick after it
 * is asked for, so the visit waits that tick to find it, whether kept or on
 * its way, and the server is asked only once.
 */
export function chooseRegion(key: string): void {
    prefetchRegion(key);
    window.setTimeout(() => router.get(addressOf(key), {}, VISIT), 0);
}

/** Drops a region's kept visit, so what it showed is never shown again: after a save, a publish, a discard, or leaving it. */
export function forgetRegion(key: string): void {
    router.flushByCacheTags(tagOf(key));
}

/** The options of a mutation that changes a region: its kept visit is dropped once the server has answered. */
export function changesRegion(key: string): { invalidateCacheTags: string[] } {
    return { invalidateCacheTags: [tagOf(key)] };
}

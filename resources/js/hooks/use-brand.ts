import { usePage } from '@inertiajs/react';
import type { Brand } from '@/types/brand';

/** The shared `brand` prop, with the site's name beside it. */
export function useBrand(): Brand & { name: string } {
    const { brand, name } = usePage().props as unknown as {
        brand: Brand;
        name: string;
    };

    return { ...brand, name };
}

/** The line under the site's name: "Teme 0.1.0 · production", or "Teme · production" before sign-in. */
export function brandLine(brand: Brand): string {
    const product =
        brand.version === null
            ? brand.product
            : `${brand.product} ${brand.version}`;

    return `${product} · ${brand.environment}`;
}

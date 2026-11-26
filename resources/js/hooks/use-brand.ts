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

/** The line under the name: "Teme 0.1.0 · production", or "Teme · production" before sign-in. */
export function brandLine(brand: Brand, named = false): string {
    // Where the product's name is already the heading, the line leaves it out.
    const product = named
        ? (brand.version ?? '')
        : brand.version === null
          ? brand.product
          : `${brand.product} ${brand.version}`;

    if (product === '') {
        return brand.environment;
    }

    return `${product} · ${brand.environment}`;
}

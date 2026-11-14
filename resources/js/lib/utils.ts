import type { InertiaLinkProps } from '@inertiajs/react';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { extendTailwindMerge } from 'tailwind-merge';

/**
 * tailwind-merge, told the design system's own sizes and shadows, so that
 * `text-body` beside `text-ink` is read as a size and a colour and both are
 * kept (docs/architecture/design-system.md, sections 2.5 and 2.8).
 */
const twMerge = extendTailwindMerge({
    extend: {
        classGroups: {
            'font-size': [
                {
                    text: [
                        'caption',
                        'small',
                        'body',
                        'lead',
                        'title',
                        'display',
                    ],
                },
            ],
            shadow: [{ shadow: ['raised', 'float', 'modal'] }],
        },
    },
});

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(url: NonNullable<InertiaLinkProps['href']>): string {
    return typeof url === 'string' ? url : url.url;
}

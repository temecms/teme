import { Search } from 'lucide-react';
import type { ComponentProps } from 'react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

/**
 * A list's search box (docs/architecture/design-system.md, section 4.26):
 * the field with a magnifier always inside it, at its start. The field takes
 * every prop an Input does; `className` sizes the whole box.
 */
export function SearchField({
    className,
    ...props
}: Omit<ComponentProps<typeof Input>, 'type'>) {
    return (
        <span
            data-slot="search-field"
            className={cn('relative inline-flex items-center', className)}
        >
            <Search
                aria-hidden="true"
                className="text-ink-3 pointer-events-none absolute start-3 size-4"
            />
            <Input type="search" className="ps-8" {...props} />
        </span>
    );
}

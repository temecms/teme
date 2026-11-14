import { Circle } from 'lucide-react';
import type { LucideProps } from 'lucide-react';
import { lucideIcon } from '@/lib/lucide-icons';

/**
 * One menu icon from the whole Lucide set, which loads as one file the
 * first time the static map lacks a name: a Lucide icon when the name is
 * one, and a circle when it isn't.
 */
export default function DynamicNavIcon({
    name,
    ...props
}: LucideProps & { name: string }) {
    const Icon = lucideIcon(name);

    if (Icon !== undefined) {
        return <Icon data-icon={name} {...props} />;
    }

    return <Circle data-icon="circle" {...props} />;
}

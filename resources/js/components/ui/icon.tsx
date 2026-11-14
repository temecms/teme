import type { LucideIcon } from 'lucide-react';

interface IconProps {
    iconNode?: LucideIcon | null;
    className?: string;
}

/** A Lucide icon at the design system's stroke (docs/architecture/design-system.md, section 2.9). */
export function Icon({ iconNode: IconComponent, className }: IconProps) {
    if (!IconComponent) {
        return null;
    }

    return <IconComponent className={className} strokeWidth={1.75} />;
}

import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

/**
 * The way through a list, the same on every screen: two outline buttons,
 * both always drawn, disabled when there is nowhere to go.
 */
export function Pager({
    newer,
    older,
    labels = { newer: 'Newer', older: 'Older' },
    preserveState = false,
}: {
    newer: string | null;
    older: string | null;
    labels?: { newer: string; older: string };
    /** Keeps the page's own state across the visit, as the Media screen does. */
    preserveState?: boolean;
}) {
    return (
        <nav
            aria-label="Pages"
            className="flex items-center justify-between gap-2"
        >
            {newer ? (
                <Button variant="outline" asChild>
                    <Link href={newer} preserveState={preserveState}>
                        {labels.newer}
                    </Link>
                </Button>
            ) : (
                <Button variant="outline" disabled>
                    {labels.newer}
                </Button>
            )}
            {older ? (
                <Button variant="outline" asChild>
                    <Link href={older} preserveState={preserveState}>
                        {labels.older}
                    </Link>
                </Button>
            ) : (
                <Button variant="outline" disabled>
                    {labels.older}
                </Button>
            )}
        </nav>
    );
}

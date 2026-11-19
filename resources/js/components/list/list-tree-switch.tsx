import { Link } from '@inertiajs/react';
import { SoonTag } from '@/components/ui/soon-tag';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';

const ITEM =
    'text-small text-ink-2 hover:text-ink aria-[current=page]:bg-surface aria-[current=page]:text-ink aria-[current=page]:shadow-raised focus-visible:outline-ring inline-flex h-(--ctl-h-xs) items-center rounded-sm px-3 font-medium focus-visible:outline-2 focus-visible:outline-offset-2';

/**
 * A nesting type's two ways to show its items: the list and the tree, as
 * links drawn as a segmented pair. The tree is marked Soon: it shows the
 * items in place, and moving them there is not built yet.
 */
export function ListTreeSwitch({
    listHref,
    treeHref,
    current,
}: {
    listHref: string;
    treeHref: string;
    current: 'list' | 'tree';
}) {
    return (
        <nav
            aria-label={__('Show as')}
            data-test="list-tree-switch"
            className="bg-surface-3 inline-flex shrink-0 items-center gap-0.5 rounded-md p-1"
        >
            <Link
                href={listHref}
                aria-current={current === 'list' ? 'page' : undefined}
                className={ITEM}
            >
                {__('List')}
            </Link>
            <Link
                href={treeHref}
                aria-current={current === 'tree' ? 'page' : undefined}
                className={cn(ITEM, 'gap-1')}
            >
                {__('Tree')}
                <SoonTag className="ms-1" />
            </Link>
        </nav>
    );
}

import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import { ChevronRight, FileText, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import ContentListController from '@/actions/App/Http/Admin/Contents/ContentListController';
import { ListTreeSwitch } from '@/components/list/list-tree-switch';
import { SearchField } from '@/components/list/search-field';
import { ListPage } from '@/components/patterns/list-page';
import { Button } from '@/components/ui/button';
import { Panel } from '@/components/ui/panel';
import { SoonTag } from '@/components/ui/soon-tag';
import { StatusPill } from '@/components/ui/status-pill';
import { useSectionCrumb } from '@/lib/crumbs';
import { formatDate } from '@/lib/dates';
import { __, __n } from '@/lib/i18n';
import { shownTitle } from '@/lib/titles';
import { cn } from '@/lib/utils';
import {
    edit as contentEdit,
    index as contentIndex,
    tree as contentTree,
} from '@/routes/content';

type TreeNode = {
    id: string;
    title: string;
    status: string;
    updatedAt: string;
    address: string | null;
    children: TreeNode[];
};

type TreePage = {
    type: string;
    labels: { singular: string; plural: string };
    nodes: TreeNode[];
    /** Whether items were left out past `most`. */
    cut: boolean;
    most: number;
};

/** How many items sit under one, at every depth. */
const under = (node: TreeNode): number =>
    node.children.reduce((total, child) => total + 1 + under(child), 0);

/** Whether the node or anything under it has these words in its title. */
const matches = (node: TreeNode, words: string): boolean =>
    node.title.toLowerCase().includes(words) ||
    node.children.some((child) => matches(child, words));

/** Every node that has children, at every depth. */
const parents = (nodes: TreeNode[]): string[] =>
    nodes.flatMap((node) =>
        node.children.length > 0 ? [node.id, ...parents(node.children)] : [],
    );

/** An item's state on its pill, as the list draws it. */
function TreeStatus({ status }: { status: string }) {
    if (status === 'published') {
        return <StatusPill tone="ok">{__('Published')}</StatusPill>;
    }

    if (status === 'scheduled') {
        return <StatusPill tone="info">{__('Scheduled')}</StatusPill>;
    }

    if (status === 'review') {
        return <StatusPill tone="warn">{__('In review')}</StatusPill>;
    }

    return <StatusPill tone="neutral">{__('Draft')}</StatusPill>;
}

/**
 * The Pages tree, marked Soon: a nesting type's items under their parents,
 * each with its address, state and last change. It folds and finds; moving
 * items here (Move up, Move down, Move under…) is not built yet.
 */
export default function ContentTree(page: TreePage) {
    const [words, setWords] = useState('');
    const [closed, setClosed] = useState<Set<string>>(new Set());
    const allParents = useMemo(() => parents(page.nodes), [page.nodes]);
    const singular = page.labels.singular.toLowerCase();
    const title = __(':plural tree', { plural: page.labels.plural });
    const finding = words.trim().toLowerCase();

    const contentCrumb = useSectionCrumb('content');

    setLayoutProps({
        breadcrumbs: [
            contentCrumb,
            {
                title: page.labels.plural,
                href: contentIndex({ type: page.type }),
            },
            { title, href: contentTree({ type: page.type }) },
        ],
    });

    const toggle = (id: string) =>
        setClosed((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });

    const rows = (nodes: TreeNode[], depth: number) => (
        <ul
            role={depth === 0 ? 'tree' : 'group'}
            aria-label={depth === 0 ? page.labels.plural : undefined}
            className={cn('grid', depth === 0 ? 'p-2' : 'ps-6')}
        >
            {nodes
                .filter((node) => finding === '' || matches(node, finding))
                .map((node) => {
                    const count = under(node);
                    const open = finding !== '' || !closed.has(node.id);
                    const dim =
                        finding !== '' &&
                        !node.title.toLowerCase().includes(finding);

                    return (
                        <li
                            key={node.id}
                            role="treeitem"
                            aria-expanded={count > 0 ? open : undefined}
                            aria-selected={false}
                        >
                            <div
                                data-test="tree-row"
                                className="hover:bg-surface-2 flex min-h-12 items-center gap-2 rounded-md py-2 ps-2 pe-3"
                            >
                                <span className="grid size-(--ctl-h-sm) shrink-0 place-items-center">
                                    {count > 0 && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-xs"
                                            aria-label={
                                                open
                                                    ? __(
                                                          'Hide the :count under :title',
                                                          {
                                                              count: __n(
                                                                  ':count page|:count pages',
                                                                  count,
                                                              ),
                                                              title: shownTitle(
                                                                  node.title,
                                                              ),
                                                          },
                                                      )
                                                    : __(
                                                          'Show the :count under :title',
                                                          {
                                                              count: __n(
                                                                  ':count page|:count pages',
                                                                  count,
                                                              ),
                                                              title: shownTitle(
                                                                  node.title,
                                                              ),
                                                          },
                                                      )
                                            }
                                            onClick={() => toggle(node.id)}
                                        >
                                            <ChevronRight
                                                aria-hidden="true"
                                                className={cn(
                                                    'transition-[rotate] duration-(--dur-1)',
                                                    open && 'rotate-90',
                                                )}
                                            />
                                        </Button>
                                    )}
                                </span>
                                <Link
                                    href={contentEdit(node.id).url}
                                    className={cn(
                                        'text-body focus-visible:outline-ring min-w-0 truncate rounded-sm font-semibold hover:underline focus-visible:outline-2 focus-visible:outline-offset-2',
                                        dim ? 'text-ink-3' : 'text-ink',
                                    )}
                                >
                                    {shownTitle(node.title)}
                                </Link>
                                {count > 0 && (
                                    <span className="bg-surface-3 text-caption text-ink-2 inline-flex h-5 shrink-0 items-center rounded-full px-2 font-medium">
                                        {__n(
                                            ':count page under it|:count pages under it',
                                            count,
                                        )}
                                    </span>
                                )}
                                <span className="text-small text-ink-3 min-w-0 truncate font-mono max-md:hidden">
                                    {node.address ?? __('No address yet')}
                                </span>
                                <span className="flex-1" />
                                <TreeStatus status={node.status} />
                                <span className="text-small text-ink-3 w-24 shrink-0 text-end tabular-nums max-sm:hidden">
                                    {formatDate(node.updatedAt)}
                                </span>
                            </div>
                            {count > 0 &&
                                open &&
                                rows(node.children, depth + 1)}
                        </li>
                    );
                })}
        </ul>
    );

    return (
        <ListPage
            title={title}
            badge={<SoonTag className="ms-0" />}
            description={__(
                'Pages nested under their parents. A page’s address follows its parents, so moving one under another changes it.',
            )}
            actions={
                <Form
                    {...ContentListController.store.form({ type: page.type })}
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="primary"
                            disabled={processing}
                        >
                            <Plus aria-hidden="true" />
                            {__('New :singular', { singular })}
                        </Button>
                    )}
                </Form>
            }
        >
            <Head title={title} />

            <div className="flex flex-wrap items-center gap-2">
                <ListTreeSwitch
                    listHref={contentIndex({ type: page.type }).url}
                    treeHref={contentTree({ type: page.type }).url}
                    current="tree"
                />
                <SearchField
                    value={words}
                    onChange={(event) => setWords(event.target.value)}
                    placeholder={__('Find a page')}
                    aria-label={__('Find a page in the tree')}
                    className="w-full sm:w-70"
                />
                <span className="flex-1" />
                <Button
                    type="button"
                    variant="ghost"
                    onClick={() => setClosed(new Set())}
                >
                    {__('Show everything')}
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    onClick={() => setClosed(new Set(allParents))}
                >
                    {__('Hide the nested pages')}
                </Button>
            </div>

            <Panel aria-label={page.labels.plural}>
                {page.nodes.length === 0 ? (
                    <div className="grid justify-items-center gap-2 px-6 py-12 text-center">
                        <FileText
                            aria-hidden="true"
                            className="text-ink-3 size-6"
                        />
                        <h2 className="text-body text-ink font-semibold">
                            {__('No pages yet')}
                        </h2>
                        <p className="text-body text-ink-2">
                            {__(
                                'Fixed pages such as About and Contact are made here.',
                            )}
                        </p>
                    </div>
                ) : (
                    rows(page.nodes, 0)
                )}
            </Panel>

            {page.cut && (
                <p className="text-small text-ink-3">
                    {__(
                        'The first :most pages are shown. Find the rest in the list.',
                        { most: page.most },
                    )}
                </p>
            )}
        </ListPage>
    );
}

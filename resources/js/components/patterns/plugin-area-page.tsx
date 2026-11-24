import { Component } from 'react';
import type { ReactNode } from 'react';
import { InPageNav } from '@/components/patterns/in-page-nav';
import { Page } from '@/components/patterns/page';
import { PageHeader } from '@/components/patterns/page-header';
import { ErrorState } from '@/components/ui/error-state';
import { Panel } from '@/components/ui/panel';
import { PluginIcon } from '@/components/ui/plugin-icon';
import { __ } from '@/lib/i18n';

export type PluginAreaPageProps = {
    /** The plugin whose area this is: its name, and its picture once plugins can ship one. */
    plugin: { name: string; icon?: string | null };
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
    /** The area's pages, as InPageNav draws them; none or one draws no navigation. */
    pages?: {
        href: string;
        label: string;
        count?: number;
        current?: boolean;
        soon?: boolean;
    }[];
    /** `div` where the page already has its h1, as in the catalogue. */
    titleAs?: 'h1' | 'div';
    /** Keep the large title when it is not the breadcrumb's last crumb. */
    showTitle?: boolean;
    children: ReactNode;
};

/** What a plugin draws can fail; the admin around it must not (docs/architecture/plugins.md). */
class PluginAreaBoundary extends Component<
    { plugin: string; children: ReactNode },
    { failed: boolean }
> {
    state = { failed: false };

    static getDerivedStateFromError(): { failed: boolean } {
        return { failed: true };
    }

    render(): ReactNode {
        if (this.state.failed) {
            return (
                <Panel data-test="plugin-area-failed">
                    <ErrorState
                        className="pt-5"
                        message={__(
                            'Couldn’t show this page of :name. The rest of the admin is unaffected.',
                            { name: this.props.plugin },
                        )}
                        onRetry={() => this.setState({ failed: false })}
                    />
                </Panel>
            );
        }

        return this.props.children;
    }
}

/**
 * A plugin's own area (docs/architecture/design-system.md, section 5): the
 * plugin's icon at 64 beside the page header, its mark after the title, the
 * area's pages as in-page navigation, five shown and the rest under More,
 * then what the plugin draws, inside a boundary that keeps a failure to one
 * sentence naming the plugin.
 */
export function PluginAreaPage({
    plugin,
    title,
    description,
    actions,
    pages = [],
    titleAs,
    showTitle,
    children,
}: PluginAreaPageProps) {
    // A new page of the area starts afresh, so a failure doesn't follow it.
    const current = pages.find((page) => page.current)?.href ?? title;

    return (
        <Page>
            <div className="flex min-w-0 items-start gap-4">
                <PluginIcon
                    size={64}
                    name={plugin.name}
                    src={plugin.icon ?? null}
                    className="mt-2 shrink-0"
                />
                <div className="min-w-0 flex-1">
                    <PageHeader
                        title={title}
                        description={description}
                        actions={actions}
                        plugin={plugin.name}
                        showTitle={showTitle}
                        as={titleAs}
                    />
                </div>
            </div>
            {pages.length > 1 && (
                <InPageNav
                    label={__(':name pages', { name: plugin.name })}
                    items={pages}
                />
            )}
            <PluginAreaBoundary key={current} plugin={plugin.name}>
                {children}
            </PluginAreaBoundary>
        </Page>
    );
}

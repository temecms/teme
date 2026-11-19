import { Progress } from '@/components/ui/progress';
import { StatusPill } from '@/components/ui/status-pill';
import { TextButton } from '@/components/ui/text-button';
import { __, __n } from '@/lib/i18n';
import type { RegionFacts } from './checks';
import { Row } from './publish-panel';

/**
 * A theme region's facts at the top of its rail, in place of a post's
 * Publish section (docs/architecture/design-system.md, 4.39): the theme that
 * uses it and whether that theme is in use, what it takes, how many of its
 * blocks are used against its limit, and why it has no title, address,
 * preview or featured image.
 */
export function RegionFactsSection({
    themeName,
    inUse,
    facts,
}: {
    themeName: string;
    inUse: boolean;
    facts: RegionFacts;
}) {
    const over = facts.blocks - facts.max;

    return (
        <section
            aria-label={__('Region')}
            data-test="region-facts"
            className="px-2"
        >
            <dl className="grid gap-2 px-3 pb-4">
                <Row label={__('Used by')}>
                    <span className="flex flex-wrap items-center gap-2">
                        {themeName}
                        {inUse ? (
                            <StatusPill tone="ok">{__('In use')}</StatusPill>
                        ) : (
                            <StatusPill tone="neutral">
                                {__('Not in use')}
                            </StatusPill>
                        )}
                    </span>
                </Row>
                <Row label={__('Takes')}>
                    {[__('Text'), ...facts.takes].join(', ')}
                </Row>
                <Row label={__('Blocks')}>
                    <span className="grid gap-1 py-1">
                        <span className="flex items-baseline justify-between gap-2">
                            <strong
                                data-test="region-blocks"
                                className="font-semibold"
                            >
                                {__(':count of at most :max', {
                                    count: facts.blocks,
                                    max: facts.max,
                                })}
                            </strong>
                            <span className="text-ink-3 text-caption">
                                {over > 0
                                    ? __('Over by :count', { count: over })
                                    : over === 0
                                      ? __('Full')
                                      : __n(
                                            ':count more fits|:count more fit',
                                            -over,
                                        )}
                            </span>
                        </span>
                        <Progress
                            label={__('Blocks used')}
                            value={Math.min(facts.blocks, facts.max)}
                            max={facts.max}
                            tone={over > 0 ? 'danger' : 'accent'}
                        />
                    </span>
                </Row>
            </dl>
            <p className="text-caption text-ink-3 px-3 pb-4">
                {__(
                    'A region is not a page, so it has no title, address, preview or featured image. Visitors see it on their next page after you update.',
                )}
            </p>
        </section>
    );
}

/** A region's History row, under its Checks. */
export function RegionHistory({
    revisionCount,
    onOpenHistory,
}: {
    revisionCount: number;
    onOpenHistory: () => void;
}) {
    return (
        <dl className="grid px-5 py-2">
            <Row label={__('History')}>
                <TextButton onClick={onOpenHistory} className="font-normal">
                    {__n(':count revision|:count revisions', revisionCount)}
                </TextButton>
            </Row>
        </dl>
    );
}

import { CheckCircle2, CircleAlert, Search } from 'lucide-react';
import { TextButton } from '@/components/ui/text-button';
import { __, __n } from '@/lib/i18n';
import type { PluginCheckGroup, PluginCheckResult } from '@/types/editor';
import type { BadLink, CoreCheck, RegionFacts } from './checks';
import type { WritingCheck } from './writing-checks';
import { PluginChecks } from './plugin-checks';
import { RailSection } from './rail-section';
import { searchCheckWords } from './search-box';
import { quotedTitle } from '@/lib/titles';

/** One link to an unreachable article, named. */
function badLinkLine(link: BadLink): string {
    if (link.state === 'missing' || link.title === null) {
        return __('An item that no longer exists');
    }

    return link.state === 'trashed'
        ? __('“:title” is in the trash', { title: quotedTitle(link.title) })
        : __('“:title” is not published', {
              title: quotedTitle(link.title),
          });
}

function StateIcon({ check }: { check: CoreCheck }) {
    return check.state === 'ok' ? (
        <CheckCircle2 aria-hidden className="text-ok-ink size-4 shrink-0" />
    ) : (
        <CircleAlert aria-hidden className="text-warn-ink size-4 shrink-0" />
    );
}

function Fact({ check }: { check: CoreCheck }) {
    return (
        <>
            {' '}
            <span className="text-ink-2">{check.message}</span>
            {check.id === 'links' && check.badLinks.length > 0 && (
                <ul className="text-ink-2 text-caption mt-1 grid gap-0.5 pl-5.5">
                    {check.badLinks.map((link) => (
                        <li key={link.id}>{badLinkLine(link)}</li>
                    ))}
                </ul>
            )}
        </>
    );
}

function Label({ check }: { check: CoreCheck }) {
    return (
        <>
            <span className="font-medium">{check.label}</span>
            <span className="sr-only">
                {' '}
                {check.state === 'ok' ? __('passed') : __('warning')}
            </span>
        </>
    );
}

/**
 * Core's checks as a list of plain facts (I-880): Search and social draws its
 * search checks with it, and the rail all of them.
 */
export function CheckList({
    checks,
    testId,
}: {
    checks: CoreCheck[];
    testId: string;
}) {
    return (
        <ul data-test={testId} className="text-small grid gap-2">
            {checks.map((check) => (
                <li key={check.id}>
                    <span className="inline-flex items-center gap-2 align-top">
                        <StateIcon check={check} />
                        <Label check={check} />
                    </span>
                    <Fact check={check} />
                </li>
            ))}
        </ul>
    );
}

/**
 * The rail's Checks (docs/architecture/design-system.md, 4.39): the writing
 * checks, each a plain fact with Show where the rail can put the cursor on
 * it; then how many search checks want a look, linking to Search and social
 * under the text; then the plugins' checks, under their plugin's names. They
 * warn and never block: nothing here disables Publish, Schedule or Submit.
 */
export function ChecksPanel({
    checks,
    onGoTo,
    searchCount,
    onGoToSearch,
    pluginChecks = [],
    onGoToPlugin,
}: {
    checks: WritingCheck[];
    onGoTo: (check: WritingCheck['id']) => void;
    /** How many of Search and social's checks want a look. */
    searchCount: number;
    /** Opens Search and social under the text; absent where there is none (a theme's region). */
    onGoToSearch?: () => void;
    /** The plugins' checks, after core's, each under its plugin's name (docs/guides/developers/plugins/seo.md). */
    pluginChecks?: PluginCheckGroup[];
    onGoToPlugin?: (code: string, result: PluginCheckResult) => void;
}) {
    const failing = checks.filter((check) => !check.ok).length;

    return (
        <RailSection
            title={__('Checks')}
            triggerId="rail-checks-trigger"
            testId="rail-checks-section"
            summary={
                failing === 0
                    ? __('All clear')
                    : __n(':count to look at|:count to look at', failing)
            }
        >
            <ul data-test="rail-checks" className="text-small grid gap-2">
                {checks.map((check) => (
                    <li
                        key={check.id}
                        data-test={`rail-check-${check.id}`}
                        data-state={check.ok ? 'ok' : 'warn'}
                        className="text-ink-2 flex items-start gap-2"
                    >
                        {check.ok ? (
                            <CheckCircle2
                                aria-hidden
                                className="text-ok-ink mt-px size-4 shrink-0"
                            />
                        ) : (
                            <CircleAlert
                                aria-hidden
                                className="text-warn-ink mt-px size-4 shrink-0"
                            />
                        )}
                        <span className="min-w-0">
                            <span className="sr-only">
                                {check.ok ? __('Passed:') : __('Warning:')}{' '}
                            </span>
                            {check.message}
                            {!check.ok && check.canShow && (
                                <>
                                    {' · '}
                                    <TextButton
                                        onClick={() => onGoTo(check.id)}
                                        className="font-normal"
                                    >
                                        {__('Show')}
                                    </TextButton>
                                </>
                            )}
                            {check.badLinks.length > 0 && (
                                <ul className="text-caption mt-1 grid gap-1">
                                    {check.badLinks.map((link) => (
                                        <li key={link.id}>
                                            {badLinkLine(link)}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </span>
                    </li>
                ))}
            </ul>
            {onGoToSearch !== undefined && (
                <TextButton
                    data-test="rail-search-note"
                    onClick={onGoToSearch}
                    className="text-small justify-self-start font-normal"
                >
                    <Search aria-hidden className="size-4" />
                    {__('Search and social: :words', {
                        words: searchCheckWords(searchCount),
                    })}
                </TextButton>
            )}
            {pluginChecks.length > 0 && (
                <PluginChecks
                    groups={pluginChecks}
                    onGoTo={onGoToPlugin}
                    testId="rail-plugin-checks"
                />
            )}
            <p className="text-caption text-ink-3">
                {pluginChecks.length > 0
                    ? __('A warning never blocks publishing.')
                    : __(
                          'A plugin’s checks join this list, named by their plugin; none of the enabled plugins adds one. A warning never blocks publishing.',
                      )}
            </p>
        </RailSection>
    );
}

/** One of a theme region's checks, worded: a plain fact; a warning never blocks a draft. */
type RegionCheck = {
    id: 'blocks' | 'cards' | 'alt';
    state: 'ok' | 'warn';
    message: string;
};

/** "a"; "a and b"; "a, b and c". */
function andList(words: string[]): string {
    return words.length < 2
        ? (words[0] ?? '')
        : __(':list and :last', {
              list: words.slice(0, -1).join(', '),
              last: words[words.length - 1],
          });
}

/** A region's facts as its three checks, in words. */
function regionChecks(facts: RegionFacts): RegionCheck[] {
    const { blocks, max, refused, takes, noAlt } = facts;
    const over = blocks - max;

    return [
        {
            id: 'blocks',
            state: blocks > 0 && over <= 0 ? 'ok' : 'warn',
            message:
                over > 0
                    ? __n(
                          'This region takes at most :max, and it has :count. Remove :over block to publish.|This region takes at most :max, and it has :count. Remove :over blocks to publish.',
                          over,
                          { max, count: blocks, over },
                      )
                    : blocks > 0
                      ? __(':count of at most :max.', { count: blocks, max })
                      : __(
                            'Empty. The theme draws nothing here until the region has something in it.',
                        ),
        },
        {
            id: 'cards',
            state: refused.length === 0 ? 'ok' : 'warn',
            message:
                refused.length > 0
                    ? __n(
                          ':cards isn’t taken by this region.|:cards aren’t taken by this region.',
                          refused.length,
                          { cards: andList(refused) },
                      )
                    : takes.length > 0
                      ? __('Only :cards cards are used.', {
                            cards: andList([__('text'), ...takes]),
                        })
                      : __('Only text is used.'),
        },
        {
            id: 'alt',
            state: noAlt === 0 ? 'ok' : 'warn',
            message:
                noAlt > 0
                    ? __n(
                          ':count image has no alt text.|:count images have no alt text.',
                          noAlt,
                      )
                    : __('No images without alt text.'),
        },
    ];
}

/**
 * A theme region's Checks (issue 529): its own facts, not a post's search
 * checks. They warn and never block a draft; publishing is refused by the
 * server past the limit or with a card the region doesn't take.
 */
export function RegionChecksPanel({ facts }: { facts: RegionFacts }) {
    const checks = regionChecks(facts);

    return (
        <RailSection title={__('Checks')} triggerId="rail-checks-trigger">
            <ul
                data-test="rail-region-checks"
                className="text-small grid gap-2"
            >
                {checks.map((check) => (
                    <li
                        key={check.id}
                        data-check={check.id}
                        data-state={check.state}
                        className="flex items-start gap-2"
                    >
                        <span className="flex h-lh shrink-0 items-center">
                            {check.state === 'ok' ? (
                                <CheckCircle2
                                    aria-hidden
                                    className="text-ok-ink size-4"
                                />
                            ) : (
                                <CircleAlert
                                    aria-hidden
                                    className="text-warn-ink size-4"
                                />
                            )}
                        </span>
                        <span>
                            <span className="sr-only">
                                {check.state === 'ok'
                                    ? __('passed')
                                    : __('warning')}
                                {': '}
                            </span>
                            {check.message}
                        </span>
                    </li>
                ))}
            </ul>
            <p
                data-test="rail-region-checks-note"
                className="text-ink-2 text-caption mt-3 text-pretty"
            >
                {__(
                    'A warning never blocks saving a draft. Publishing is refused while the region is over its limit or holds a card it does not take.',
                )}
            </p>
        </RailSection>
    );
}

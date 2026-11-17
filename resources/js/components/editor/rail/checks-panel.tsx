import { CheckCircle2, CircleAlert } from 'lucide-react';
import { TextButton } from '@/components/ui/text-button';
import { __ } from '@/lib/i18n';
import type { PluginCheckGroup, PluginCheckResult } from '@/types/editor';
import type { BadLink, CheckId, CoreCheck } from './checks';
import { PluginChecks } from './plugin-checks';
import { RailSection } from './rail-section';
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

/** The checks the rail can put the cursor on: every one but these. */
const NOWHERE_TO_GO: CheckId[] = ['listing', 'structured-data'];

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
 * The rail's Checks: core's whole list (I-880), worked out in the browser as
 * the person writes. A warning the rail can put the cursor on is a button
 * that does. They warn and never block: nothing here disables Publish,
 * Schedule or Submit.
 */
export function ChecksPanel({
    checks,
    onGoTo,
    onGoToSearch,
    noun,
    pluginChecks = [],
    onGoToPlugin,
}: {
    checks: CoreCheck[];
    onGoTo: (check: CheckId) => void;
    /** The plugins' checks, after core's, each under its plugin's name (docs/guides/developers/plugins/seo.md). */
    pluginChecks?: PluginCheckGroup[];
    onGoToPlugin?: (code: string, result: PluginCheckResult) => void;
    /** Opens Search and social under the text; absent where there is none (a theme's region). */
    onGoToSearch?: () => void;
    noun: string;
}) {
    return (
        <RailSection title={__('Checks')} triggerId="rail-checks-trigger">
            <ul data-test="rail-checks" className="text-small grid gap-2">
                {checks.map((check) => (
                    <li key={check.id}>
                        {check.state === 'warn' &&
                        !NOWHERE_TO_GO.includes(check.id) ? (
                            <TextButton
                                onClick={() => onGoTo(check.id)}
                                className="gap-2 text-left align-top font-normal decoration-transparent underline-offset-2 hover:underline"
                            >
                                <StateIcon check={check} />
                                <Label check={check} />
                            </TextButton>
                        ) : (
                            <span className="inline-flex items-center gap-2 align-top">
                                <StateIcon check={check} />
                                <Label check={check} />
                            </span>
                        )}
                        <Fact check={check} />
                    </li>
                ))}
            </ul>
            {pluginChecks.length > 0 && (
                <div className="border-line mt-3 border-t pt-3">
                    <PluginChecks
                        groups={pluginChecks}
                        onGoTo={onGoToPlugin}
                        testId="rail-plugin-checks"
                    />
                </div>
            )}
            {onGoToSearch !== undefined && (
                <div
                    data-test="rail-search-note"
                    className="bg-surface-2 text-caption mt-3 grid gap-2 rounded-md px-3 py-3"
                >
                    <p className="text-ink-2">
                        {__('Search and social is under the :noun.', { noun })}
                    </p>
                    <TextButton
                        onClick={onGoToSearch}
                        className="justify-self-start font-normal decoration-current"
                    >
                        {__('Go to Search and social')}
                    </TextButton>
                </div>
            )}
        </RailSection>
    );
}

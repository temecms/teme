import { CheckCircle2, CircleAlert } from 'lucide-react';
import type { BadLink, CheckId, CoreCheck } from './checks';
import { RailSection } from './rail-section';
import { quotedTitle } from '@/lib/titles';

/** One link to an unreachable article, named. */
function badLinkLine(link: BadLink): string {
    if (link.state === 'missing' || link.title === null) {
        return 'An item that no longer exists';
    }

    return link.state === 'trashed'
        ? `“${quotedTitle(link.title)}” is in the trash`
        : `“${quotedTitle(link.title)}” is not published`;
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
                <ul className="text-ink-2 mt-0.5 grid gap-0.5 pl-[22px] text-xs">
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
                {check.state === 'ok' ? ' passed' : ' warning'}
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
        <ul data-test={testId} className="grid gap-1.5 text-[13px]">
            {checks.map((check) => (
                <li key={check.id}>
                    <span className="inline-flex items-center gap-1.5 align-top">
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
}: {
    checks: CoreCheck[];
    onGoTo: (check: CheckId) => void;
    /** Opens Search and social under the text; absent where there is none (a theme's region). */
    onGoToSearch?: () => void;
    noun: string;
}) {
    return (
        <RailSection title="Checks" triggerId="rail-checks-trigger">
            <ul data-test="rail-checks" className="grid gap-1.5 text-[13px]">
                {checks.map((check) => (
                    <li key={check.id}>
                        {check.state === 'warn' &&
                        !NOWHERE_TO_GO.includes(check.id) ? (
                            <button
                                type="button"
                                onClick={() => onGoTo(check.id)}
                                className="text-accent-ink focus-visible:ring-ring inline-flex min-h-6 items-center gap-1.5 rounded-sm text-left align-top underline-offset-2 outline-none hover:underline focus-visible:ring-2"
                            >
                                <StateIcon check={check} />
                                <Label check={check} />
                            </button>
                        ) : (
                            <span className="inline-flex items-center gap-1.5 align-top">
                                <StateIcon check={check} />
                                <Label check={check} />
                            </span>
                        )}
                        <Fact check={check} />
                    </li>
                ))}
            </ul>
            {onGoToSearch !== undefined && (
                <div
                    data-test="rail-search-note"
                    className="bg-surface-2 mt-3 grid gap-1.5 rounded-md px-3 py-2.5 text-xs"
                >
                    <p className="text-ink-2">
                        Search and social is under the {noun}.
                    </p>
                    <button
                        type="button"
                        onClick={onGoToSearch}
                        className="text-accent-ink focus-visible:ring-ring min-h-6 justify-self-start rounded-sm underline outline-none focus-visible:ring-2"
                    >
                        Go to Search and social
                    </button>
                </div>
            )}
        </RailSection>
    );
}

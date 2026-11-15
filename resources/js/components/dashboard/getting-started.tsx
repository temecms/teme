import { Link } from '@inertiajs/react';
import { CheckCircle2, Circle } from 'lucide-react';
import { useState } from 'react';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { TextButton } from '@/components/ui/text-button';
import { __ } from '@/lib/i18n';
import { savePreferences } from '@/lib/preferences';
import type { GettingStartedStep } from '@/types';

/**
 * Getting started (I-241): what a new site still needs, each ticked off from
 * the site's real state, with a way to hide it. Hiding is the person's own
 * preference; it comes back from Account › Preferences.
 */
export function GettingStarted({ steps }: { steps: GettingStartedStep[] }) {
    const [hidden, setHidden] = useState(false);
    const done = steps.filter((step) => step.done).length;

    if (hidden) {
        return null;
    }

    return (
        <Panel aria-labelledby="getting-started">
            <PanelHeader>
                <h2 id="getting-started" className="text-body font-semibold">
                    {__('Getting started')}
                </h2>
                <span className="text-small text-ink-2 font-normal">
                    {__(':done of :total done', {
                        done,
                        total: steps.length,
                    })}
                </span>
                <span className="flex-1" />
                <TextButton
                    type="button"
                    onClick={() =>
                        void savePreferences({
                            'dashboard.getting_started_hidden': true,
                        }).then((saved) => setHidden(saved))
                    }
                >
                    {__('Hide this')}
                </TextButton>
            </PanelHeader>
            <PanelBody className="gap-0">
                <ul>
                    {steps.map((step) => (
                        <li
                            key={step.key}
                            className="text-body flex min-h-11 items-center gap-2 py-2 [&_svg]:size-4"
                        >
                            {step.done ? (
                                <>
                                    <CheckCircle2
                                        aria-hidden="true"
                                        className="text-ok-ink"
                                    />
                                    <span className="text-ink-2">
                                        {step.label}
                                    </span>
                                </>
                            ) : (
                                <>
                                    <Circle
                                        aria-hidden="true"
                                        className="text-ink-2"
                                    />
                                    <Link
                                        href={step.url}
                                        className="text-accent-ink underline-offset-2 hover:underline"
                                    >
                                        {step.label}
                                    </Link>
                                </>
                            )}
                        </li>
                    ))}
                </ul>
            </PanelBody>
        </Panel>
    );
}

import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { KeySheet } from '@/components/backups/key-sheet';
import { SetupSteps } from '@/components/backups/setup-steps';
import { TypedBack } from '@/components/backups/typed-back';
import { PageHeader } from '@/components/list/page-header';
import { index } from '@/routes/tools';
import { index as backups } from '@/routes/tools/backups';
import type { RecoveryKeyPageProps } from '@/types/backups';

/**
 * A new recovery key, shown once (I-2401), then two of its groups typed back.
 * The key lives in this page's props, which the server sent to this one
 * response; leaving the page drops it.
 */
export default function RecoveryKey(page: RecoveryKeyPageProps) {
    const [typing, setTyping] = useState(false);
    const title =
        page.purpose === 'set-up'
            ? 'Your recovery key'
            : `${page.label}: your new recovery key`;

    return (
        <>
            <Head title="Your recovery key" />

            <div className="max-w-3xl space-y-6 p-6">
                <PageHeader title={title} />

                {page.purpose === 'set-up' && (
                    <SetupSteps current={typing ? 3 : 2} />
                )}

                {/* Both stay mounted, so Back to the key keeps what was typed. */}
                <div hidden={typing}>
                    <KeySheet page={page} onContinue={() => setTyping(true)} />
                </div>
                <div hidden={!typing}>
                    <TypedBack
                        positions={page.positions}
                        challengeId={page.challengeId}
                        onBack={() => setTyping(false)}
                        focusFirst={typing}
                    />
                </div>
            </div>
        </>
    );
}

RecoveryKey.layout = {
    breadcrumbs: [
        { title: 'Tools', href: index() },
        { title: 'Backups', href: backups() },
        { title: 'Your recovery key', href: backups() },
    ],
};

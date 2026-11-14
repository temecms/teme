import { Head, setLayoutProps } from '@inertiajs/react';
import { useState } from 'react';
import { KeySheet } from '@/components/backups/key-sheet';
import { SetupSteps } from '@/components/backups/setup-steps';
import { TypedBack } from '@/components/backups/typed-back';
import { FormPage } from '@/components/patterns/form-page';
import { __ } from '@/lib/i18n';
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
            ? __('Your recovery key')
            : __(':label: your new recovery key', { label: page.label });

    setLayoutProps({
        breadcrumbs: [
            { title: __('Tools'), href: index() },
            { title: __('Backups'), href: backups() },
            { title: __('Your recovery key'), href: backups() },
        ],
    });

    return (
        <>
            <Head title={__('Your recovery key')} />

            <FormPage title={title}>
                <div className="grid gap-6">
                    {page.purpose === 'set-up' && (
                        <SetupSteps current={typing ? 3 : 2} />
                    )}

                    {/* Both stay mounted, so Back to the key keeps what was typed. */}
                    <div hidden={typing}>
                        <KeySheet
                            page={page}
                            onContinue={() => setTyping(true)}
                        />
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
            </FormPage>
        </>
    );
}

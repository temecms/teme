import { Printer } from 'lucide-react';
import { createPortal } from 'react-dom';
import { Button } from '@/components/ui/button';
import { Notice } from '@/components/ui/notice';
import { Panel, PanelBody, PanelHeader } from '@/components/ui/panel';
import { names } from '@/lib/backup-words';
import { __ } from '@/lib/i18n';
import type { RecoveryKeyPageProps } from '@/types/backups';

/** Only the sheet prints: everything else in the page is hidden on paper. */
const PRINT_ONLY = `@media print {
    body > :not(#key-print-sheet) { display: none !important; }
    #key-print-sheet { display: block !important; background: var(--paper); color: CanvasText; color-scheme: light; }
}`;

/** The sentences about keeping a key, looked up as they are drawn. */
const sentencesAboutKeeping = () => [
    __(
        'Write it down or print it, and keep it somewhere other than this server.',
    ),
    __(
        'If this server is lost, this key is the only way to open your backups. Anyone who has it and a copy of them can read them.',
    ),
    __(
        'To open them with restic itself, type it exactly as shown, with its hyphens.',
    ),
];

/**
 * A recovery key as it is shown, once (I-2401): its eight groups, numbered,
 * the sentences about keeping it, Print and Continue, and the sheet that
 * prints. There is no Copy: a clipboard keeps what it is given.
 */
export function KeySheet({
    page,
    onContinue,
}: {
    page: RecoveryKeyPageProps;
    onContinue: () => void;
}) {
    const replaced = page.purpose === 'replace';
    const sentences = replaced
        ? [
              ...sentencesAboutKeeping(),
              __(
                  'Your old key for :label keeps working until you’ve typed part of this one back.',
                  { label: page.label },
              ),
          ]
        : sentencesAboutKeeping();

    return (
        <section className="grid gap-5">
            <Notice tone="warn">
                <strong className="font-semibold">
                    {__('Teme shows this key once.')}
                </strong>{' '}
                {__(
                    'It keeps no copy, so it cannot show it again. Leaving this page drops it.',
                )}
            </Notice>

            <Panel aria-labelledby="key-sheet-title">
                <PanelHeader>
                    <h2
                        id="key-sheet-title"
                        className="text-lead font-semibold"
                    >
                        {__('Recovery key for :site', { site: page.site })}
                    </h2>
                </PanelHeader>
                <PanelBody className="text-body gap-5">
                    <p className="text-small text-ink-2">
                        {__('Made :date for the backups at :places.', {
                            date: page.madeOn,
                            places: names(page.places),
                        })}
                    </p>

                    <ol
                        aria-label={__('Recovery key, eight groups')}
                        className="grid grid-cols-2 gap-3 sm:grid-cols-4"
                    >
                        {page.groups.map((group, index) => (
                            <li
                                key={index}
                                data-test="key-group"
                                className="bg-surface-2 grid gap-1 rounded-md p-4"
                            >
                                <span className="text-small text-ink-3">
                                    {__('Group :number', {
                                        number: index + 1,
                                    })}
                                </span>
                                <span
                                    data-test="key-group-value"
                                    className="text-lead font-mono font-semibold"
                                >
                                    {group}
                                </span>
                            </li>
                        ))}
                    </ol>

                    <ul className="grid list-disc gap-2 ps-5">
                        {sentences.map((sentence) => (
                            <li key={sentence}>{sentence}</li>
                        ))}
                    </ul>

                    <p className="text-small text-ink-2">
                        {__(
                            'There is no Copy button on purpose: a clipboard keeps what it is given.',
                        )}
                    </p>

                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => window.print()}
                            data-test="key-print"
                        >
                            <Printer aria-hidden="true" />
                            {__('Print this key')}
                        </Button>
                        <Button
                            type="button"
                            onClick={onContinue}
                            data-test="key-continue"
                        >
                            {__('I’ve kept it safe: continue')}
                        </Button>
                    </div>
                </PanelBody>
            </Panel>

            {createPortal(
                <div id="key-print-sheet" className="hidden p-10">
                    <style>{PRINT_ONLY}</style>
                    <h1 className="text-title font-semibold">
                        {__('Recovery key for :site', { site: page.site })}
                    </h1>
                    <p className="mt-2">
                        {__('Made :date for the backups at :places.', {
                            date: page.madeOn,
                            places: names(page.places),
                        })}
                    </p>
                    <ol className="mt-8 grid grid-cols-4 gap-6">
                        {page.groups.map((group, index) => (
                            <li key={index} data-test="key-sheet-group">
                                <span className="text-small block">
                                    {index + 1}
                                </span>
                                <span className="text-display font-mono">
                                    {group}
                                </span>
                            </li>
                        ))}
                    </ol>
                    <div className="mt-8 grid gap-2">
                        {sentences.map((sentence) => (
                            <p key={sentence}>{sentence}</p>
                        ))}
                        <p>
                            {__(
                                'Keep this sheet somewhere other than the server: a safe, a drawer at home, or a sealed envelope with someone you trust.',
                            )}
                        </p>
                    </div>
                </div>,
                document.body,
            )}
        </section>
    );
}

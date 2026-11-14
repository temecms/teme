import { Printer } from 'lucide-react';
import { createPortal } from 'react-dom';
import { Button } from '@/components/ui/button';
import { names } from '@/lib/backup-words';
import type { RecoveryKeyPageProps } from '@/types/backups';

/** Only the sheet prints: everything else in the page is hidden on paper. */
const PRINT_ONLY = `@media print {
    body > :not(#key-print-sheet) { display: none !important; }
    #key-print-sheet { display: block !important; background: white; color: black; color-scheme: light; }
}`;

const SENTENCES = [
    'Write it down or print it, and keep it somewhere other than this server.',
    'If this server is lost, this key is the only way to open your backups. Anyone who has it and a copy of them can read them.',
    'To open them with restic itself, type it exactly as shown, with its hyphens.',
];

/**
 * A recovery key as it is shown, once (I-2401): its eight groups, numbered,
 * four sentences about keeping it, Print and Continue, and the sheet that
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
              ...SENTENCES,
              `Your old key for ${page.label} keeps working until you’ve typed part of this one back.`,
          ]
        : SENTENCES;

    return (
        <section className="space-y-5">
            <div className="space-y-1">
                <p className="text-muted-foreground text-[13px]">
                    Teme shows it once and keeps no copy.
                </p>
            </div>

            <ol
                aria-label="Recovery key, eight groups"
                className="grid max-w-xl grid-cols-2 gap-3 sm:grid-cols-4"
            >
                {page.groups.map((group, index) => (
                    <li
                        key={index}
                        data-test="key-group"
                        className="border-border rounded-md border p-3"
                    >
                        <span
                            aria-hidden="true"
                            className="text-muted-foreground block text-xs"
                        >
                            {index + 1}
                        </span>
                        <span className="sr-only">Group {index + 1}: </span>
                        <span
                            data-test="key-group-value"
                            className="font-mono text-xl tracking-wider"
                        >
                            {group}
                        </span>
                    </li>
                ))}
            </ol>

            <div className="max-w-xl space-y-2 text-sm">
                {sentences.map((sentence) => (
                    <p key={sentence}>{sentence}</p>
                ))}
            </div>

            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => window.print()}
                    data-test="key-print"
                >
                    <Printer aria-hidden="true" />
                    Print this key
                </Button>
                <Button
                    type="button"
                    onClick={onContinue}
                    data-test="key-continue"
                >
                    I’ve kept it safe: continue
                </Button>
            </div>

            {createPortal(
                <div id="key-print-sheet" className="hidden p-10">
                    <style>{PRINT_ONLY}</style>
                    <h1 className="text-2xl font-semibold">
                        Recovery key for {page.site}
                    </h1>
                    <p className="mt-2">
                        Made {page.madeOn} for the backups at{' '}
                        {names(page.places)}.
                    </p>
                    <ol className="mt-8 grid grid-cols-4 gap-6">
                        {page.groups.map((group, index) => (
                            <li key={index} data-test="key-sheet-group">
                                <span className="block text-sm">
                                    {index + 1}
                                </span>
                                <span className="font-mono text-3xl">
                                    {group}
                                </span>
                            </li>
                        ))}
                    </ol>
                    <div className="mt-8 space-y-2">
                        {sentences.map((sentence) => (
                            <p key={sentence}>{sentence}</p>
                        ))}
                        <p>
                            Keep this sheet somewhere other than the server: a
                            safe, a drawer at home, or a sealed envelope with
                            someone you trust.
                        </p>
                    </div>
                </div>,
                document.body,
            )}
        </section>
    );
}

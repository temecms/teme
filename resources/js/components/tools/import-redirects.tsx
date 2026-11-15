import { Form } from '@inertiajs/react';
import ImportRedirectsController from '@/actions/App/Http/Admin/Tools/ImportRedirectsController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { ScrollArea } from '@/components/ui/scroll-area';
import { __, __n } from '@/lib/i18n';
import type { ImportReport } from '@/types';

const SAMPLE = `from,to,status,note
/old-fares,/news/ferry-fares-to-bonny-island-rise-15-from-october,301,
/obituaries/[...rest],/news/[...rest],301,The old section`;

/** The report's title: how many were imported, and how many lines left out. */
function importTitle(report: ImportReport): string {
    const added =
        report.added === 0
            ? __('No redirect')
            : __n(':count redirect|:count redirects', report.added, {
                  count: report.added.toLocaleString(),
              });
    const left = report.refused.length;

    return __(':added imported, :lines left out', {
        added,
        lines: __n(':count line|:count lines', left, {
            count: left.toLocaleString(),
        }),
    });
}

/**
 * Importing redirects from a CSV file (I-872): the dialog that takes the
 * file, and the report of the lines it left out.
 */
export function ImportRedirects({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {__('Import redirects from a CSV file')}
                    </DialogTitle>
                    <DialogDescription>
                        {__(
                            'One redirect a line: from, to, status, and a note if you like. To is a path on this site or a full address, and stays empty for 410 and 451. A fifth column, on, says yes or no, as an export writes it; without it, a redirect is on.',
                        )}
                    </DialogDescription>
                </DialogHeader>

                <ScrollArea
                    axis="x"
                    label={__('Example lines')}
                    className="bg-surface-2 rounded-md"
                >
                    <pre className="text-small p-3 font-mono">{SAMPLE}</pre>
                </ScrollArea>

                <Form
                    {...ImportRedirectsController.form()}
                    onSuccess={() => onOpenChange(false)}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <Field
                                htmlFor="redirects-file"
                                label={__('CSV file')}
                                help={__(
                                    'Each line is checked as one added here would be. Lines that fail are left out, and you’re told why.',
                                )}
                                error={errors.file ?? errors.csv}
                            >
                                <Input
                                    id="redirects-file"
                                    name="file"
                                    type="file"
                                    accept=".csv,text/csv"
                                />
                            </Field>

                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button type="button" variant="outline">
                                        {__('Cancel')}
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="redirects-import-submit"
                                >
                                    {__('Import redirects')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/** The lines an import left out, each with why, shown once after it. */
export function ImportReportDialog({
    report,
    onClose,
}: {
    report: ImportReport | null;
    onClose: () => void;
}) {
    return (
        <Dialog
            open={report !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {report === null ? '' : importTitle(report)}
                    </DialogTitle>
                    <DialogDescription>
                        {__(
                            'These lines weren’t imported. Fix them in the file, then import those lines again.',
                        )}
                    </DialogDescription>
                </DialogHeader>

                <ScrollArea className="max-h-80" label={__('Lines left out')}>
                    <ul className="text-body grid list-disc gap-1 ps-5">
                        {report?.refused.map((refused) => (
                            <li key={refused.line}>{refused.reason}</li>
                        ))}
                    </ul>
                </ScrollArea>

                <DialogFooter>
                    <Button type="button" onClick={onClose}>
                        {__('Done')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

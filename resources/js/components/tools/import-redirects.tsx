import { Form } from '@inertiajs/react';
import ImportRedirectsController from '@/actions/App/Http/Admin/Tools/ImportRedirectsController';
import InputError from '@/components/input-error';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ImportReport } from '@/types';

const SAMPLE = `from,to,status,note
/old-fares,/news/ferry-fares-to-bonny-island-rise-15-from-october,301,
/obituaries/[...rest],/news/[...rest],301,The old section`;

/** The report's title: how many were imported, and how many lines left out. */
function importTitle(report: ImportReport): string {
    const added =
        report.added === 0
            ? 'No redirect'
            : report.added === 1
              ? '1 redirect'
              : `${report.added.toLocaleString()} redirects`;
    const left = report.refused.length;

    return `${added} imported, ${left === 1 ? '1 line' : `${left.toLocaleString()} lines`} left out`;
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
                    <DialogTitle>Import redirects from a CSV file</DialogTitle>
                    <DialogDescription>
                        One redirect a line: from, to, status, and a note if you
                        like. To is a path on this site or a full address, and
                        stays empty for 410 and 451. A fifth column, on, says
                        yes or no, as an export writes it; without it, a
                        redirect is on.
                    </DialogDescription>
                </DialogHeader>

                <pre className="bg-muted overflow-x-auto rounded-md p-3 font-mono text-xs">
                    {SAMPLE}
                </pre>

                <Form
                    {...ImportRedirectsController.form()}
                    onSuccess={() => onOpenChange(false)}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="redirects-file">CSV file</Label>
                                <Input
                                    id="redirects-file"
                                    name="file"
                                    type="file"
                                    accept=".csv,text/csv"
                                    aria-describedby="redirects-file-hint redirects-file-error"
                                />
                                <p
                                    id="redirects-file-hint"
                                    className="text-muted-foreground text-xs"
                                >
                                    Each line is checked as one added here would
                                    be. Lines that fail are left out, and you’re
                                    told why.
                                </p>
                                <InputError
                                    id="redirects-file-error"
                                    message={errors.file ?? errors.csv}
                                />
                            </div>

                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button type="button" variant="outline">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="redirects-import-submit"
                                >
                                    Import redirects
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
                        These lines weren’t imported. Fix them in the file, then
                        import those lines again.
                    </DialogDescription>
                </DialogHeader>

                <ul className="max-h-80 list-disc space-y-1 overflow-y-auto pl-5 text-sm">
                    {report?.refused.map((refused) => (
                        <li key={refused.line}>{refused.reason}</li>
                    ))}
                </ul>

                <DialogFooter>
                    <Button type="button" onClick={onClose}>
                        Done
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

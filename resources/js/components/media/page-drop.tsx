import { Upload } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

/** Whether a drag carries files, rather than text or a link. */
function carriesFiles(event: DragEvent): boolean {
    return Array.from(event.dataTransfer?.types ?? []).includes('Files');
}

/** A drawer or a dialog is open: a drop must never upload behind it. */
function somethingOpen(): boolean {
    return (
        document.querySelector('[role="dialog"], [role="alertdialog"]') !== null
    );
}

/**
 * Files dropped anywhere on the screen upload, with "Drop files to upload"
 * over it while they're dragged, unless a drawer or dialog is open (I-440).
 * A file drag never falls through to the browser, which would open the file
 * in place of the admin. Dropping is a pointer gesture: Upload files is its
 * keyboard equal, so the overlay is hidden from screen readers.
 */
export function PageDrop({ onFiles }: { onFiles: (files: FileList) => void }) {
    const [over, setOver] = useState(false);
    const depth = useRef(0);
    const latest = useRef(onFiles);

    useEffect(() => {
        latest.current = onFiles;
    }, [onFiles]);

    useEffect(() => {
        // Something on the page already took this drag (defaultPrevented): it is left alone.
        const enter = (event: DragEvent) => {
            if (
                event.defaultPrevented ||
                !carriesFiles(event) ||
                somethingOpen()
            ) {
                return;
            }

            event.preventDefault();
            depth.current += 1;
            setOver(true);
        };

        const dragOver = (event: DragEvent) => {
            if (event.defaultPrevented) {
                return;
            }

            if (carriesFiles(event)) {
                event.preventDefault();
            }
        };

        // Leaving one element for another inside the page counts down and up again.
        const leave = () => {
            depth.current = Math.max(0, depth.current - 1);

            if (depth.current === 0) {
                setOver(false);
            }
        };

        const drop = (event: DragEvent) => {
            depth.current = 0;
            setOver(false);

            if (event.defaultPrevented || !carriesFiles(event)) {
                return;
            }

            event.preventDefault();

            if (!somethingOpen() && event.dataTransfer !== null) {
                latest.current(event.dataTransfer.files);
            }
        };

        window.addEventListener('dragenter', enter);
        window.addEventListener('dragover', dragOver);
        window.addEventListener('dragleave', leave);
        window.addEventListener('drop', drop);

        return () => {
            window.removeEventListener('dragenter', enter);
            window.removeEventListener('dragover', dragOver);
            window.removeEventListener('dragleave', leave);
            window.removeEventListener('drop', drop);
        };
    }, []);

    if (!over) {
        return null;
    }

    return (
        <div
            data-test="page-drop"
            aria-hidden="true"
            className="bg-background/90 border-primary pointer-events-none fixed inset-0 z-40 flex items-center justify-center border-2 border-dashed text-sm font-medium"
        >
            <span className="flex items-center gap-2">
                <Upload className="size-5" />
                Drop files to upload
            </span>
        </div>
    );
}

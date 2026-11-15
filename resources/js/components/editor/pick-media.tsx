import { useCallback, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { MediaModal } from '@/components/media/media-modal';
import { __ } from '@/lib/i18n';
import type { MediaKind, MediaRow, UploadTarget } from '@/types';

/** What the picker is opened for: it sets the title and the main button's words (DS 4.38). */
export type MediaPickerCaller =
    | 'insert'
    | 'replace'
    | 'featured'
    | 'gallery'
    | 'choose';

export type MediaPickerOptions = {
    caller: MediaPickerCaller;
    /** Several may be chosen; they reach onInsert in the order chosen. */
    multi: boolean;
    /** Called once, with at least one file, when the main button is pressed. */
    onInsert: (ids: string[], rows: MediaRow[]) => void;
    /** The kinds the grid offers; images when left out. */
    kinds?: MediaKind[];
    /** The main button's words where the caller's differ ("Use as poster"); required for 'choose'. */
    action?: string;
    /** The item open in the editor, which an upload made here records (I-553). */
    uploadedFor?: UploadTarget | null;
    /** Called when it closes without a choice. */
    onClose?: () => void;
};

/** The main button's words for each caller, looked up while drawing. */
function callerAction(caller: MediaPickerCaller): string {
    switch (caller) {
        case 'insert':
            return __('Insert image');
        case 'replace':
            return __('Replace');
        case 'featured':
            return __('Set featured image');
        case 'gallery':
            // The library's own dialog cannot count the choice; openMediaPicker says "Add :count images".
            return __('Use these images');
        case 'choose':
            return __('Choose a file');
    }
}

/**
 * The editor's one way to open the media picker (docs/plans/editor.md, D2):
 * openMediaPicker's options, drawn with MediaModal until it merges. The
 * caller renders `picker` once and calls `open`; `initial` opens it with the
 * caller, as a new image card does.
 */
export function useMediaPicker(initial: MediaPickerOptions | null = null): {
    open: (options: MediaPickerOptions) => void;
    picker: ReactNode;
} {
    // The last options stay after closing, so the dialog closes as it opened and gives focus back.
    const [options, setOptions] = useState<MediaPickerOptions | null>(initial);
    const [shown, setShown] = useState(initial !== null);
    // The library's dialog closes itself after a choice: that close is not "without a choice".
    const chosen = useRef(false);
    const open = useCallback((next: MediaPickerOptions) => {
        chosen.current = false;
        setOptions(next);
        setShown(true);
    }, []);
    const picker = options && (
        <MediaModal
            open={shown}
            onOpenChange={(next) => {
                setShown(next);

                if (!next && !chosen.current) {
                    options.onClose?.();
                }
            }}
            kinds={options.kinds ?? ['image']}
            multiple={options.multi}
            action={options.action ?? callerAction(options.caller)}
            onChoose={(ids, rows) => {
                chosen.current = true;
                options.onInsert(ids, rows);
            }}
        />
    );

    return { open, picker };
}

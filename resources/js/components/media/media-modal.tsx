import { useUploadTarget } from '@/components/editor/cards/card-environment';
import { MediaPickerDialog } from '@/components/media/media-picker';
import type { MediaKind, MediaRow } from '@/types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    kinds: MediaKind[];
    multiple?: boolean;
    action: string;
    onChoose: (ids: string[], rows: MediaRow[]) => void;
};

/**
 * The one media picker, held open by its caller: the screens that have not
 * moved to openMediaPicker yet draw it this way (I-3400). Each opening starts
 * afresh, on the Library tab with nothing chosen.
 */
export function MediaModal({
    open,
    onOpenChange,
    kinds,
    multiple = false,
    action,
    onChoose,
}: Props) {
    const uploadedFor = useUploadTarget();

    if (!open) {
        return null;
    }

    return (
        <MediaPickerDialog
            open
            onOpenChange={onOpenChange}
            caller="choose"
            multi={multiple}
            kinds={kinds}
            action={action}
            uploadedFor={uploadedFor}
            onInsert={(ids, rows) => {
                onChoose(ids, rows);
                onOpenChange(false);
            }}
        />
    );
}

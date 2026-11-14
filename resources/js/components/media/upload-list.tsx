import { Button } from '@/components/ui/button';
import type { useMediaUploads } from '@/components/media/use-media-uploads';

/**
 * The files on their way into the library, each with its bar, or its failure
 * and Retry, or a duplicate's two choices. The list itself is the live
 * region, so each change is announced.
 */
export function UploadList({
    uploads,
}: {
    uploads: ReturnType<typeof useMediaUploads>;
}) {
    return (
        <ul
            data-test="media-uploads"
            aria-live="polite"
            className="grid gap-2 text-sm empty:hidden"
        >
            {uploads.entries.map((entry) => (
                <li key={entry.key} className="grid gap-1">
                    <div className="flex items-center justify-between gap-2">
                        <span className="truncate">{entry.file.name}</span>
                        {entry.state === 'failed' && (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => uploads.retry(entry)}
                            >
                                Retry
                            </Button>
                        )}
                        {entry.state === 'duplicate' && (
                            <span className="flex gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        uploads.chooseExisting(entry)
                                    }
                                >
                                    Use the existing one
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() => uploads.uploadAnyway(entry)}
                                >
                                    Upload anyway
                                </Button>
                            </span>
                        )}
                    </div>
                    {(entry.state === 'waiting' ||
                        entry.state === 'sending') && (
                        <progress
                            className="h-1 w-full"
                            value={entry.progress}
                            max={1}
                            aria-label={`Uploading ${entry.file.name}`}
                        />
                    )}
                    {entry.state === 'failed' && (
                        <p className="text-danger-ink">{entry.message}</p>
                    )}
                    {entry.state === 'duplicate' && (
                        <p className="text-ink-2">
                            Already in the library as “{entry.duplicateTitle}”.
                        </p>
                    )}
                </li>
            ))}
        </ul>
    );
}

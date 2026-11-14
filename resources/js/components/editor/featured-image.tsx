import { MediaControl } from '@/components/editor/fields/media-control';
import type { EditorField } from '@/types';

/**
 * The image that stands for the article in lists, feeds and social cards:
 * its label and help text around the media control, which chooses it from
 * the library and takes its alt text for this use. It is never decorative
 * (I-237).
 */
export function FeaturedImage({
    field,
    value,
    onChange,
}: {
    field: EditorField;
    value: unknown;
    onChange: (value: unknown) => void;
}) {
    return (
        <section
            aria-labelledby="editor-featured-label"
            className="grid grid-cols-[minmax(0,1fr)] gap-2"
        >
            <span id="editor-featured-label" className="text-sm font-medium">
                {field.label}
            </span>
            <MediaControl
                field={field}
                value={value}
                onChange={onChange}
                id="editor-featured"
                idPrefix="editor-featured"
                featured
            />
            <p className="text-ink-2 text-xs">{field.description}</p>
        </section>
    );
}

import { Link } from '@inertiajs/react';
import { Image as ImageIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { __ } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * A list row's title cell (docs/architecture/design-system.md, section
 * 4.26): the thumbnail when the person shows them, then the title, which
 * wraps to two lines before an ellipsis with the whole title in a tooltip,
 * then the row's line of actions. Nothing else in a row adds lines (I-3399).
 */
export function TitleCell({
    title,
    href,
    thumbnail,
    after,
    line,
    className,
}: {
    /** The title as shown, "Untitled" for none. */
    title: string;
    /** Where the title goes, the editor; null keeps it plain text, for a row the person may not open. */
    href: string | null;
    /** The thumbnail's address, null for none (an image icon), undefined to show no thumbnail at all. */
    thumbnail?: string | null;
    /** Marks after the title, such as its language's tag. */
    after?: ReactNode;
    /** The row's line of actions. */
    line?: ReactNode;
    className?: string;
}) {
    const words = cn(
        'text-body text-ink line-clamp-2 leading-snug font-semibold text-pretty group-data-[slot=list-card]/card:line-clamp-3',
        href !== null &&
            'focus-visible:outline-ring rounded-sm hover:underline focus-visible:outline-2 focus-visible:outline-offset-2',
    );

    return (
        <div data-test="title-cell" className={cn('flex gap-3', className)}>
            {thumbnail !== undefined &&
                (thumbnail !== null ? (
                    <img
                        data-test="featured-thumb"
                        src={thumbnail}
                        alt=""
                        loading="lazy"
                        className="outline-img-outline h-8 w-12 shrink-0 rounded-md object-cover outline -outline-offset-1"
                    />
                ) : (
                    <span
                        data-test="featured-none"
                        className="bg-surface-3 text-ink-3 grid h-8 w-12 shrink-0 place-items-center rounded-md"
                    >
                        <ImageIcon aria-hidden="true" className="size-4" />
                        <span className="sr-only">
                            {__('No featured image')}
                        </span>
                    </span>
                ))}
            <div className="grid min-w-0 flex-1 content-start gap-1">
                <div className="flex min-w-0 items-start gap-2">
                    <Tooltip>
                        <TooltipTrigger asChild>
                            {href !== null ? (
                                <Link
                                    href={href}
                                    data-test="row-title"
                                    className={words}
                                >
                                    {title}
                                </Link>
                            ) : (
                                <span data-test="row-title" className={words}>
                                    {title}
                                </span>
                            )}
                        </TooltipTrigger>
                        <TooltipContent className="max-w-100 whitespace-normal">
                            {title}
                        </TooltipContent>
                    </Tooltip>
                    {after}
                </div>
                {line}
            </div>
        </div>
    );
}

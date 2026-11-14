import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { useInitials } from '@/hooks/use-initials';
import type { CommentDetails, CommentRow } from '@/types';

/**
 * Who wrote a comment, as the Comments screen shows them (v9's author cell):
 * their initials, their name and its chips, and, below, their email address,
 * website and, for those who see them, IP address.
 */
export function Commenter({
    row,
    seesAddresses,
    onFindAddress,
}: {
    row: CommentRow | CommentDetails;
    seesAddresses: boolean;
    onFindAddress?: (ip: string) => void;
}) {
    const getInitials = useInitials();
    const website = row.authorUrl?.replace(/^https?:\/\//u, '') ?? null;
    const ip = seesAddresses && 'ip' in row ? row.ip : null;

    return (
        <div className="flex items-start gap-3">
            <Avatar className="size-8">
                <AvatarFallback aria-hidden className="text-[11px]">
                    {getInitials(row.authorName)}
                </AvatarFallback>
            </Avatar>
            <div className="min-w-0 space-y-0.5">
                <p className="flex flex-wrap items-center gap-1.5">
                    <span className="font-semibold">{row.authorName}</span>
                    {row.signedIn && (
                        <Badge variant="secondary">Signed in</Badge>
                    )}
                    {row.kind === 'pingback' && (
                        <Badge variant="outline">Pingback</Badge>
                    )}
                    {row.kind === 'trackback' && (
                        <Badge variant="outline">Trackback</Badge>
                    )}
                </p>
                <div className="text-muted-foreground space-y-0.5 text-[12px] leading-4">
                    {row.authorEmail && <p>{row.authorEmail}</p>}
                    {website && <p>{website}</p>}
                    {ip && onFindAddress && (
                        <button
                            type="button"
                            title="Find every comment from this IP address"
                            // At least 24 pixels tall (WCAG 2.2, 2.5.8).
                            className="hover:text-foreground focus-visible:ring-ring inline-flex min-h-6 items-center rounded-sm font-mono underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:outline-none"
                            onClick={() => onFindAddress(ip)}
                        >
                            {ip}
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}

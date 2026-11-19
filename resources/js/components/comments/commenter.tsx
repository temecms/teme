import { Badge } from '@/components/ui/badge';
import { TextButton } from '@/components/ui/text-button';
import { __ } from '@/lib/i18n';
import type { CommentDetails, CommentRow } from '@/types';

/**
 * Who wrote a comment, as the Comments screen shows them: their name and its
 * chips, and, below, their email address, website and, for those who see
 * them, IP address as a link that finds every comment from it. No picture:
 * people's pictures show only on the screens about people.
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
    const website = row.authorUrl?.replace(/^https?:\/\//u, '') ?? null;
    const ip = seesAddresses && 'ip' in row ? row.ip : null;

    return (
        <div className="grid min-w-0 gap-0.5">
            <p className="flex flex-wrap items-center gap-1">
                <span className="font-semibold">{row.authorName}</span>
                {row.signedIn && (
                    <Badge variant="secondary">{__('Signed in')}</Badge>
                )}
                {row.kind === 'pingback' && (
                    <Badge variant="outline">{__('Pingback')}</Badge>
                )}
                {row.kind === 'trackback' && (
                    <Badge variant="outline">{__('Trackback')}</Badge>
                )}
            </p>
            {row.authorEmail && (
                <p className="text-small text-ink-2 wrap-anywhere">
                    {row.authorEmail}
                </p>
            )}
            {website && (
                <p className="text-small text-ink-2 wrap-anywhere">{website}</p>
            )}
            {ip && onFindAddress && (
                <TextButton
                    className="text-small justify-self-start font-mono"
                    title={__('Find every comment from this IP address')}
                    onClick={() => onFindAddress(ip)}
                >
                    {ip}
                </TextButton>
            )}
        </div>
    );
}

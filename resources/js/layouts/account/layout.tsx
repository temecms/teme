import type { CSSProperties, PropsWithChildren } from 'react';
import { Page } from '@/components/patterns/page';
import { AccountNav, AccountPagesButton } from './account-nav';

/** The side list beside the page from 1024px: up to 200 wide, the page taking the rest. */
const COLUMNS = {
    '--account-cols': 'minmax(0, 200px) minmax(0, 1fr)',
} as CSSProperties;

/**
 * The frame of every account page (docs/architecture/design-system.md,
 * section 4.43): the account's side list headed by you, and the page's
 * column; under 1024px one button opens the list.
 */
export default function AccountLayout({ children }: PropsWithChildren) {
    return (
        <Page>
            <div
                className="grid items-start gap-8 lg:grid-cols-(--account-cols)"
                style={COLUMNS}
            >
                <AccountNav />
                <div className="grid min-w-0 gap-4">
                    <AccountPagesButton />
                    {children}
                </div>
            </div>
        </Page>
    );
}

import { usePage } from '@inertiajs/react';
import * as DropdownMenuPrimitive from '@radix-ui/react-dropdown-menu';
import {
    ResponsiveMenu,
    ResponsiveMenuContent,
    ResponsiveMenuTrigger,
} from '@/components/patterns/responsive-menu';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { ScrollArea } from '@/components/ui/scroll-area';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';
import { __ } from '@/lib/i18n';

/**
 * The account control at the header's right edge: a 32px initials circle
 * only (design system 4.33). It opens the account menu.
 */
export function AccountMenu() {
    const { auth } = usePage().props;
    const getInitials = useInitials();

    if (!auth.user) {
        return null;
    }

    return (
        // From 640px up not modal, so the rest of the page is not hidden from screen readers while it is open; on a phone it is a modal sheet like every other.
        <ResponsiveMenu modal={false}>
            <ResponsiveMenuTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    data-test="account-menu-button"
                    aria-label={__('Account: :name', { name: auth.user.name })}
                    className="rounded-full p-0 max-md:size-11"
                >
                    <Avatar className="size-8">
                        <AvatarImage
                            src={auth.user.picture ?? undefined}
                            alt=""
                        />
                        <AvatarFallback className="bg-accent-soft-2 text-on-accent-soft text-caption font-semibold">
                            {getInitials(auth.user.name)}
                        </AvatarFallback>
                    </Avatar>
                </Button>
            </ResponsiveMenuTrigger>
            <ResponsiveMenuContent
                title={__('Account')}
                renderDesktop={(children) => (
                    // Drawn in the header, not moved to the end of the page, so the open menu stays inside the banner landmark.
                    <DropdownMenuPrimitive.Content
                        data-slot="dropdown-menu-content"
                        align="end"
                        sideOffset={4}
                        className="bg-surface text-ink motion-safe:data-[state=open]:animate-in motion-safe:data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 shadow-float z-(--z-float) flex max-h-(--radix-dropdown-menu-content-available-height) min-w-56 flex-col overflow-hidden rounded-md border"
                    >
                        <ScrollArea className="p-1">{children}</ScrollArea>
                    </DropdownMenuPrimitive.Content>
                )}
            >
                <UserMenuContent user={auth.user} />
            </ResponsiveMenuContent>
        </ResponsiveMenu>
    );
}

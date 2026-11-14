import { usePage } from '@inertiajs/react';
import * as DropdownMenuPrimitive from '@radix-ui/react-dropdown-menu';
import { ChevronDown } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { ScrollArea } from '@/components/ui/scroll-area';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';
import { __ } from '@/lib/i18n';

// Later (I-1393): the person's role beside their name, once the shared user carries it.
/**
 * The account menu at the header's right edge: the person's initials, their
 * name (hidden under 768px) and a chevron. It opens the menu the sidebar's
 * foot used to hold.
 */
export function AccountMenu() {
    const { auth } = usePage().props;
    const getInitials = useInitials();

    if (!auth.user) {
        return null;
    }

    return (
        // Not modal, so the rest of the page is not hidden from screen readers while it is open.
        <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    data-test="account-menu-button"
                    aria-label={__('Account: :name', { name: auth.user.name })}
                    className="gap-2 px-1 max-md:size-11 max-md:px-0"
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
                    <span className="text-small max-w-40 truncate max-md:hidden">
                        {auth.user.name}
                    </span>
                    <ChevronDown aria-hidden="true" className="max-md:hidden" />
                </Button>
            </DropdownMenuTrigger>
            {/* Drawn in the header, not moved to the end of the page, so the open menu stays inside the banner landmark. */}
            <DropdownMenuPrimitive.Content
                data-slot="dropdown-menu-content"
                align="end"
                sideOffset={4}
                className="bg-surface text-ink motion-safe:data-[state=open]:animate-in motion-safe:data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 shadow-float z-(--z-float) flex max-h-(--radix-dropdown-menu-content-available-height) min-w-56 flex-col overflow-hidden rounded-md border"
            >
                <ScrollArea className="p-1">
                    <UserMenuContent user={auth.user} />
                </ScrollArea>
            </DropdownMenuPrimitive.Content>
        </DropdownMenu>
    );
}

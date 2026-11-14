import { usePage } from '@inertiajs/react';
import * as DropdownMenuPrimitive from '@radix-ui/react-dropdown-menu';
import { ChevronDown } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';

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
                    aria-label={`Account: ${auth.user.name}`}
                    className="gap-2 px-1.5 max-md:size-11 max-md:px-0"
                >
                    <Avatar className="size-[26px]">
                        <AvatarImage src={auth.user.avatar} alt="" />
                        <AvatarFallback className="bg-muted border-border border text-[11px] font-semibold">
                            {getInitials(auth.user.name)}
                        </AvatarFallback>
                    </Avatar>
                    <span className="max-w-[160px] truncate max-md:hidden">
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
                className="bg-popover text-popover-foreground data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 z-50 max-h-(--radix-dropdown-menu-content-available-height) min-w-56 overflow-x-hidden overflow-y-auto rounded-md border p-1 shadow-(--shadow)"
            >
                <UserMenuContent user={auth.user} />
            </DropdownMenuPrimitive.Content>
        </DropdownMenu>
    );
}

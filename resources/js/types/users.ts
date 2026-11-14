export type UserStatus = 'active' | 'invited' | 'suspended';

export type UserRow = {
    id: number;
    name: string;
    email: string;
    role: string | null;
    status: UserStatus;
    twoFactor: boolean;
    lastSignInAt: string | null;
    invitationFailed: boolean;
    /** When the invitation was first sent; set only while it waits. */
    invitedAt: string | null;
    /** Who first sent it, while the security log still names an account. */
    invitedBy: string | null;
    /** When its link stops working; null once it has, or when none is held. */
    linkWorksUntil: string | null;
    /** The picture's address, built at render time; null for none, and always for an invitation. */
    picture: string | null;
};

export type RoleOption = { value: string; label: string };

/** One of the People screen's views, with how many it lists. */
export type PeopleTab = { key: string; label: string; count: number };

/** Someone with an account, as whom a deleted person's content can go to. */
export type ReassignOption = { value: number; label: string };

export type UsersIndexPage = {
    view: string;
    tabs: PeopleTab[];
    users: UserRow[];
    reassignOptions: ReassignOption[];
    roles: RoleOption[];
    invitationDays: number;
    currentUserId: number;
};

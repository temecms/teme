export type User = {
    id: number;
    name: string;
    email: string;
    emailVerified: boolean;
    capabilities: string[];
    /** The first role's label, or null without one. */
    role: string | null;
    /** The picture's address, or null: initials show instead. */
    picture: string | null;
};

export type Auth = {
    user: User;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};

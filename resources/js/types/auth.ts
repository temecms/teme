export type User = {
    id: number;
    name: string;
    email: string;
    emailVerified: boolean;
    capabilities: string[];
    avatar?: string;
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

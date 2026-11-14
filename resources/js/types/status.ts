/** The Status banner the admin shows at the top of every screen (I-221). */
export type StatusBanner = {
    level: 'error' | 'warning' | 'info';
    message: string;
    actionLabel: string | null;
    actionUrl: string | null;
};

import type { ReactNode } from 'react';
import type { BreadcrumbItem } from '@/types/navigation';

export type AppLayoutProps = {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
};

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
    /** Posted as it is to undo what the toast reports (1.8c's D11). */
    undo?: { href: string; data: Record<string, unknown> };
    /** Where the toast offers to go next, such as the redirect form after trashing (I-856). */
    link?: { label: string; href: string };
};

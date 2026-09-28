import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
    /** Match only the exact URL instead of the URL and its children. */
    exact?: boolean;
    /** Module is planned but not built yet: rendered muted with a "Soon" tag. */
    soon?: boolean;
};

export type NavGroup = {
    title: string;
    items: NavItem[];
};

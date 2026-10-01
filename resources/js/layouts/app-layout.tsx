import { usePage } from '@inertiajs/react';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import PortalLayout from '@/layouts/portal-layout';
import type { BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    const { auth } = usePage<{ auth: { user: { role?: string } | null } }>()
        .props;

    // Merchant users (e.g. on their settings pages) keep the portal shell.
    if (auth.user?.role === 'merchant') {
        return <PortalLayout>{children}</PortalLayout>;
    }

    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs}>
            {children}
        </AppLayoutTemplate>
    );
}

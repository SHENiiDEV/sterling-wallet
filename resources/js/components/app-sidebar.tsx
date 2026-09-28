import { Link } from '@inertiajs/react';
import {
    ArrowLeftRight,
    ArrowRightLeft as ArrowRightLeftIcon,
    Bot,
    Building2,
    CalendarCheck,
    FileStack,
    FolderKanban,
    Handshake,
    LayoutDashboard,
    Landmark,
    PieChart,
    Store,
    Tags,
    Users,
    Wallet,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarRail,
} from '@/components/ui/sidebar';
import admin from '@/routes/admin';
import type { NavGroup } from '@/types';

const navGroups: NavGroup[] = [
    {
        title: 'Overview',
        items: [
            {
                title: 'Dashboard',
                href: admin.dashboard(),
                icon: LayoutDashboard,
                exact: true,
            },
        ],
    },
    {
        title: 'Merchants',
        items: [
            {
                title: 'Merchants & MIDs',
                href: admin.merchants.index(),
                icon: Store,
            },
            {
                title: 'Companies',
                href: admin.companies.index(),
                icon: Building2,
            },
            {
                title: 'Operations',
                href: admin.operations.index(),
                icon: ArrowLeftRight,
            },
        ],
    },
    {
        title: 'Finance',
        items: [
            {
                title: 'Report Control',
                href: '#',
                icon: CalendarCheck,
                soon: true,
            },
            { title: 'Settlements', href: '#', icon: Wallet, soon: true },
            {
                title: 'Providers',
                href: admin.providers.index(),
                icon: PieChart,
            },
            {
                title: 'FX rates',
                href: admin.fxRates.index(),
                icon: ArrowRightLeftIcon,
            },
            { title: 'Profit share', href: '#', icon: Handshake, soon: true },
        ],
    },
    {
        title: 'Workspace',
        items: [
            {
                title: 'Document Center',
                href: admin.documents.index(),
                icon: FolderKanban,
            },
            {
                title: 'Document statuses',
                href: admin.documentStatuses.index(),
                icon: Tags,
            },
            { title: 'Bots', href: admin.bots.index(), icon: Bot },
            {
                title: 'Bank holidays',
                href: admin.bankHolidays.index(),
                icon: Landmark,
            },
            { title: 'Offers', href: '#', icon: FileStack, soon: true },
            { title: 'Team & access', href: '#', icon: Users, soon: true },
        ],
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={admin.dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain groups={navGroups} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
            <SidebarRail />
        </Sidebar>
    );
}

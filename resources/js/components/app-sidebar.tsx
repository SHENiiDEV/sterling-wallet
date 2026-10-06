import { Link, usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    ArrowRightLeft as ArrowRightLeftIcon,
    Bot,
    Building2,
    CalendarCheck,
    FileStack,
    FileText,
    FolderKanban,
    Handshake,
    LayoutDashboard,
    Landmark,
    PieChart,
    Store,
    Tags,
    TrendingUp,
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
                module: 'merchants',
            },
            {
                title: 'Companies',
                href: admin.companies.index(),
                icon: Building2,
                module: 'merchants',
            },
            {
                title: 'Operations',
                href: admin.operations.index(),
                icon: ArrowLeftRight,
                module: 'operations',
            },
            {
                title: 'Offers',
                href: admin.offers.index(),
                icon: FileStack,
                module: 'offers',
            },
        ],
    },
    {
        title: 'Finance',
        items: [
            {
                title: 'Report Control',
                href: admin.reports.index(),
                icon: CalendarCheck,
                module: 'reports',
            },
            {
                title: 'Settlements',
                href: admin.settlements.index(),
                icon: Wallet,
                module: 'settlements',
            },
            {
                title: 'Providers & profit',
                href: admin.profit.index(),
                icon: TrendingUp,
                module: 'profit',
            },
            {
                title: 'Profit share',
                href: admin.profitShare.index(),
                icon: Handshake,
                module: 'profit',
            },
            {
                title: 'Providers',
                href: admin.providers.index(),
                icon: PieChart,
                module: 'providers',
            },
            {
                title: 'FX rates',
                href: admin.fxRates.index(),
                icon: ArrowRightLeftIcon,
                module: 'providers',
            },
        ],
    },
    {
        title: 'Workspace',
        items: [
            {
                title: 'Document Center',
                href: admin.documents.index(),
                icon: FolderKanban,
                module: 'documents',
            },
            {
                title: 'Document templates',
                href: admin.documentTemplates.index(),
                icon: FileText,
                module: 'documents',
            },
            {
                title: 'Document statuses',
                href: admin.documentStatuses.index(),
                icon: Tags,
                module: 'documents',
            },
            {
                title: 'Bots',
                href: admin.bots.index(),
                icon: Bot,
                module: 'bots',
            },
            {
                title: 'Bank holidays',
                href: admin.bankHolidays.index(),
                icon: Landmark,
                module: 'providers',
            },
            {
                title: 'Team & access',
                href: admin.team.index(),
                icon: Users,
                module: 'team',
            },
        ],
    },
];

export function AppSidebar() {
    const { auth } = usePage().props;
    const groups = navGroups
        .map((group) => ({
            ...group,
            items: group.items.filter(
                (item) => !item.module || auth.modules.includes(item.module),
            ),
        }))
        .filter((group) => group.items.length > 0);

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
                <NavMain groups={groups} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
            <SidebarRail />
        </Sidebar>
    );
}

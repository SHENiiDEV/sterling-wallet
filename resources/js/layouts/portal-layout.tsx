import { Link, usePage } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import AppWordmark from '@/components/app-wordmark';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { UserMenuContent } from '@/components/user-menu-content';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';
import portal from '@/routes/portal';
import type { User } from '@/types';

const NAV = [
    { title: 'Dashboard', href: portal.dashboard.url(), exact: true },
    { title: 'Daily reports', href: portal.reports.url(), exact: false },
    { title: 'Settlements', href: portal.settlements.url(), exact: false },
];

/**
 * The merchant portal: a top bar instead of the admin sidebar.
 */
export default function PortalLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    const { auth, company } = usePage<{
        auth: { user: User };
        company?: string | null;
    }>().props;
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <div className="min-h-svh bg-muted/30">
            <header className="sticky top-0 z-20 border-b bg-background/95 backdrop-blur">
                <div className="mx-auto flex h-14 max-w-6xl items-center gap-6 px-4 md:px-6">
                    <Link
                        href={portal.dashboard.url()}
                        className="flex shrink-0 items-center gap-3"
                    >
                        <AppWordmark size="sm" />
                    </Link>
                    {company && (
                        <span className="hidden truncate border-l pl-4 text-sm font-medium text-muted-foreground md:block">
                            {company}
                        </span>
                    )}
                    <nav className="ml-auto flex items-center gap-1 overflow-x-auto">
                        {NAV.map((item) => (
                            <Link
                                key={item.href}
                                href={item.href}
                                className={cn(
                                    'rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap text-muted-foreground transition-colors hover:bg-muted hover:text-foreground',
                                    isCurrentUrl(
                                        item.href,
                                        undefined,
                                        !item.exact,
                                    ) && 'bg-muted text-foreground',
                                )}
                            >
                                {item.title}
                            </Link>
                        ))}
                    </nav>
                    <DropdownMenu>
                        <DropdownMenuTrigger className="flex shrink-0 items-center gap-2 rounded-md px-2 py-1 hover:bg-muted">
                            <span className="hidden max-w-48 items-center gap-2 sm:flex">
                                <UserInfo user={auth.user} />
                            </span>
                            <ChevronDown className="size-4 text-muted-foreground" />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-56">
                            <UserMenuContent user={auth.user} />
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </header>
            <main className="mx-auto grid max-w-6xl gap-6 px-4 py-6 md:px-6">
                {children}
            </main>
        </div>
    );
}

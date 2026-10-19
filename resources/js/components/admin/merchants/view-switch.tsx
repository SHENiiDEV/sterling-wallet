import { Link } from '@inertiajs/react';
import { Folder, Landmark, List } from 'lucide-react';
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';

const views = [
    { key: 'clients', label: 'Clients', icon: Folder },
    { key: 'banks', label: 'Banks', icon: Landmark },
    { key: 'list', label: 'List', icon: List },
] as const;

export type MerchantView = (typeof views)[number]['key'];

const hrefs = {
    clients: () => admin.merchants.folders(),
    banks: () => admin.merchants.banks(),
    list: () => admin.merchants.index(),
};

export function ViewSwitch({ view }: { view: MerchantView }) {
    return (
        <div className="inline-flex rounded-lg border bg-card p-0.5 shadow-xs">
            {views.map(({ key, label, icon: Icon }) => (
                <Link
                    key={key}
                    href={hrefs[key]()}
                    className={cn(
                        'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition',
                        key === view
                            ? 'bg-primary text-primary-foreground'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    <Icon className="size-4" />
                    {label}
                </Link>
            ))}
        </div>
    );
}

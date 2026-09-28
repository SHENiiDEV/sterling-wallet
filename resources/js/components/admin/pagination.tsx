import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

export function Pagination<T>({
    page,
    noun = 'results',
}: {
    page: Paginated<T>;
    noun?: string;
}) {
    if (page.total === 0) {
        return null;
    }

    return (
        <div className="flex flex-col items-center justify-between gap-3 border-t px-4 py-3 text-sm sm:flex-row">
            <p className="text-muted-foreground">
                Showing{' '}
                <span className="font-medium text-foreground">
                    {page.from}–{page.to}
                </span>{' '}
                of{' '}
                <span className="font-medium text-foreground">
                    {page.total}
                </span>{' '}
                {noun}
            </p>
            {page.last_page > 1 && (
                <nav className="flex items-center gap-1">
                    {page.links.map((link, index) => {
                        const isPrev = index === 0;
                        const isNext = index === page.links.length - 1;
                        const label = isPrev ? (
                            <ChevronLeft className="size-4" />
                        ) : isNext ? (
                            <ChevronRight className="size-4" />
                        ) : (
                            link.label
                        );
                        const className = cn(
                            'inline-flex h-8 min-w-8 items-center justify-center rounded-md px-2 text-sm tabular-nums',
                            link.active
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                            !link.url && 'pointer-events-none opacity-40',
                        );

                        return link.url ? (
                            <Link
                                key={index}
                                href={link.url}
                                preserveScroll
                                preserveState
                                className={className}
                            >
                                {label}
                            </Link>
                        ) : (
                            <span key={index} className={className}>
                                {label}
                            </span>
                        );
                    })}
                </nav>
            )}
        </div>
    );
}

import { Check, Copy, ShieldCheck } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import { getJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';

export type AuthenticatorCodeMeta = {
    id: number;
    name: string;
    service: string | null;
    notes: string | null;
    is_active: boolean;
    digits: number;
    period: number;
    links: { type: string; id: number; name: string }[];
};

type Live = Record<number, { code: string; remaining: number; period: number }>;

/**
 * The current code of each authenticator secret, with the seconds it has left and a copy button. The secret stays on
 * the server: this asks for the six digits and asks again when they run out. Drop it on any page that has codes to show.
 */
export function useLiveCodes(ids: number[]): Live {
    const [live, setLive] = useState<Live>({});
    const latest = useRef<Live>({});
    const key = ids.join(',');
    const loading = useRef(false);

    const apply = useCallback((next: Live) => {
        latest.current = next;
        setLive(next);
    }, []);

    const load = useCallback(async () => {
        if (ids.length === 0 || loading.current) {
            return;
        }
        loading.current = true;
        try {
            const query = ids.map((id) => `ids[]=${id}`).join('&');
            apply(
                await getJson<Live>(
                    `${admin.authenticator.codes.url()}?${query}`,
                ),
            );
        } catch {
            // The next tick tries again.
        } finally {
            loading.current = false;
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key, apply]);

    useEffect(() => {
        void load();
    }, [load]);

    // Count the seconds down here, and ask for fresh codes the moment one runs out (or is a few seconds from it).
    useEffect(() => {
        if (ids.length === 0) {
            return;
        }
        const timer = setInterval(() => {
            const next: Live = {};
            let expired = false;
            for (const [id, value] of Object.entries(latest.current)) {
                const remaining = value.remaining - 1;
                expired = expired || remaining <= 0;
                next[Number(id)] = { ...value, remaining };
            }
            apply(next);

            if (expired || Object.keys(next).length < ids.length) {
                void load();
            }
        }, 1000);

        return () => clearInterval(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [load, key, apply]);

    // Coming back to the tab: the countdown may have stood still while it was hidden.
    useEffect(() => {
        const refresh = () => {
            if (document.visibilityState === 'visible') {
                void load();
            }
        };
        document.addEventListener('visibilitychange', refresh);

        return () => document.removeEventListener('visibilitychange', refresh);
    }, [load]);

    return live;
}

function group(code: string): string {
    return code.length === 6
        ? `${code.slice(0, 3)} ${code.slice(3)}`
        : code.length === 8
          ? `${code.slice(0, 4)} ${code.slice(4)}`
          : code;
}

export function AuthenticatorCodeCard({
    meta,
    live,
}: {
    meta: AuthenticatorCodeMeta;
    live: Live[number] | undefined;
}) {
    const [copied, copy] = useClipboard();
    const remaining = live ? Math.max(0, live.remaining) : null;
    const justCopied = live !== undefined && copied === live.code;

    return (
        <div className="flex items-center justify-between gap-3 rounded-lg border bg-card px-3 py-2.5">
            <div className="min-w-0">
                <div className="truncate text-sm font-medium">{meta.name}</div>
                <div className="truncate text-xs text-muted-foreground">
                    {[meta.service, ...meta.links.map((l) => l.name)]
                        .filter(Boolean)
                        .join(' · ')}
                </div>
                {meta.notes && (
                    <div className="truncate text-xs text-amber-600">
                        {meta.notes}
                    </div>
                )}
            </div>
            <div className="flex shrink-0 items-center gap-2">
                <div className="text-right">
                    <div className="font-mono text-xl font-semibold tracking-wider tabular-nums">
                        {live ? group(live.code) : '··· ···'}
                    </div>
                    {remaining !== null && (
                        <div
                            className="mt-0.5 h-1 w-full overflow-hidden rounded bg-muted"
                            aria-label={`${remaining} seconds left`}
                        >
                            <div
                                className={cn(
                                    'h-full transition-[width] duration-1000 ease-linear',
                                    remaining <= 5
                                        ? 'bg-rose-500'
                                        : 'bg-emerald-500',
                                )}
                                style={{
                                    width: `${(remaining / (live?.period ?? 30)) * 100}%`,
                                }}
                            />
                        </div>
                    )}
                </div>
                <Button
                    size="icon"
                    variant="outline"
                    aria-label="Copy code"
                    disabled={!live}
                    onClick={() => live && void copy(live.code)}
                >
                    {justCopied ? <Check /> : <Copy />}
                </Button>
            </div>
        </div>
    );
}

/** A block of cards for the codes linked to the page's client, company, merchant or provider. */
export function AuthenticatorCodesBlock({
    codes,
    title = 'Authenticator codes',
}: {
    codes: AuthenticatorCodeMeta[];
    title?: string;
}) {
    const live = useLiveCodes(codes.map((c) => c.id));

    if (codes.length === 0) {
        return null;
    }

    return (
        <section className="grid gap-2 rounded-xl border bg-card p-4 shadow-xs">
            <div className="flex items-center gap-2 text-xs font-medium text-muted-foreground uppercase">
                <ShieldCheck className="size-4" />
                {title}
            </div>
            <div className="grid gap-2 md:grid-cols-2">
                {codes.map((meta) => (
                    <AuthenticatorCodeCard
                        key={meta.id}
                        meta={meta}
                        live={live[meta.id]}
                    />
                ))}
            </div>
        </section>
    );
}

import { router, useForm } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { useEffect } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import admin from '@/routes/admin';
import type { Merchant } from '@/types';

export type MerchantDeletion = {
    mids: number;
    operations: number;
    daily_reports: number;
    settlements: number;
    paid_settlements: number;
    reserve_entries: number;
    wallets: number;
    bank_links: number;
    documents_kept: number;
};

const LINES: [keyof MerchantDeletion, string][] = [
    ['operations', 'transactions (bank and gateway)'],
    ['daily_reports', 'daily reports with their PDF / XLSX / CSV'],
    ['settlements', 'settlements'],
    ['reserve_entries', 'rolling reserve entries'],
    ['mids', 'MIDs'],
    ['wallets', 'wallets'],
    ['bank_links', 'bank onboarding records'],
];

/**
 * Deleting a merchant removes everything it produced. Allowed only once it
 * is Closed, after reading what goes with it and typing its name.
 */
export function DeleteMerchantDialog({
    merchant,
    deletion,
    open,
    onOpenChange,
}: {
    merchant: Merchant;
    deletion: MerchantDeletion | undefined;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const closed = merchant.status === 'closed';
    const form = useForm({ confirm: '' });

    // Count what would go only when the dialog is opened.
    useEffect(() => {
        if (open && closed) {
            router.reload({ only: ['deletion'] });
        }
        if (!open) {
            form.reset();
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, closed]);

    const matches = form.data.confirm.trim() === merchant.name.trim();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <form
                    className="grid gap-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.delete(
                            admin.merchants.destroy.url(merchant.public_id),
                        );
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Delete {merchant.name}?</DialogTitle>
                        <DialogDescription>
                            {closed
                                ? 'This removes the merchant and all of its data. It cannot be undone.'
                                : 'Only a closed merchant can be deleted.'}
                        </DialogDescription>
                    </DialogHeader>

                    {!closed ? (
                        <div className="rounded-lg border bg-muted/40 p-3 text-sm">
                            Set the status to <strong>Closed</strong> first
                            (Edit → Status). A closed merchant gets no new
                            reports, and can then be deleted together with its
                            transactions and reports.
                        </div>
                    ) : deletion === undefined ? (
                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                            <Spinner /> Counting what will be deleted…
                        </div>
                    ) : (
                        <div className="grid gap-3">
                            <ul className="grid gap-1 rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm">
                                {LINES.map(([key, label]) => (
                                    <li
                                        key={key}
                                        className="flex justify-between gap-4"
                                    >
                                        <span>{label}</span>
                                        <span className="font-medium tabular-nums">
                                            {deletion[key].toLocaleString()}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            {deletion.paid_settlements > 0 && (
                                <p className="flex gap-2 text-sm text-destructive">
                                    <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                                    {deletion.paid_settlements} of the
                                    settlements were already paid out — their
                                    record and proof of payment will be gone.
                                </p>
                            )}
                            {deletion.documents_kept > 0 && (
                                <p className="text-sm text-muted-foreground">
                                    {deletion.documents_kept} document(s) stay
                                    with the company and are only unlinked from
                                    this merchant.
                                </p>
                            )}
                            <div className="grid gap-2">
                                <Label htmlFor="confirm-name">
                                    Type <strong>{merchant.name}</strong> to
                                    confirm
                                </Label>
                                <Input
                                    id="confirm-name"
                                    autoComplete="off"
                                    value={form.data.confirm}
                                    onChange={(e) =>
                                        form.setData('confirm', e.target.value)
                                    }
                                />
                                <InputError
                                    message={
                                        form.errors.confirm ??
                                        (form.errors as Record<string, string>)
                                            .merchant
                                    }
                                />
                            </div>
                        </div>
                    )}

                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </DialogClose>
                        {closed && (
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={
                                    !matches ||
                                    deletion === undefined ||
                                    form.processing
                                }
                            >
                                {form.processing && <Spinner />}
                                Delete everything
                            </Button>
                        )}
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

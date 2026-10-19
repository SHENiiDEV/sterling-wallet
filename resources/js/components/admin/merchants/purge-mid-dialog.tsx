import { useForm } from '@inertiajs/react';
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
import type { Mid } from '@/types';

/**
 * A MID that already has history is deleted together with its operations,
 * reports and reserve: after reading what goes and typing the MID number.
 */
export function PurgeMidDialog({
    merchantId,
    mid,
    onClose,
}: {
    merchantId: string;
    mid: Mid | null;
    onClose: () => void;
}) {
    const form = useForm({ confirm: '' });

    useEffect(() => {
        form.reset();
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [mid?.id]);

    const matches = mid !== null && form.data.confirm.trim() === mid.mid.trim();
    // The server may also refuse with a `mid` error (e.g. a paid settlement).
    const bag = form.errors as Record<string, string | undefined>;
    const errors = [bag.mid, bag.confirm].filter(Boolean);

    return (
        <Dialog open={mid !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <form
                    className="grid gap-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (!mid) return;
                        form.delete(
                            admin.merchants.mids.purge.url({
                                merchant: merchantId,
                                mid: mid.id,
                            }),
                            { preserveScroll: true, onSuccess: onClose },
                        );
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            Delete MID {mid?.mid} with its history?
                        </DialogTitle>
                        <DialogDescription>
                            This cannot be undone. Raw provider files stay, so
                            uploading them again brings the rows back (to
                            whichever MID they then belong to).
                        </DialogDescription>
                    </DialogHeader>

                    <ul className="grid gap-1 rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm">
                        <li className="flex justify-between gap-4">
                            <span>transactions (bank and gateway rows)</span>
                            <span className="font-medium tabular-nums">
                                {(mid?.operations_count ?? 0).toLocaleString()}
                            </span>
                        </li>
                        <li className="flex justify-between gap-4">
                            <span>daily reports with their files</span>
                            <span className="font-medium tabular-nums">
                                {(
                                    mid?.daily_reports_count ?? 0
                                ).toLocaleString()}
                            </span>
                        </li>
                        <li className="text-muted-foreground">
                            …and its rolling reserve entries and the lines of
                            draft settlements built from these reports.
                        </li>
                    </ul>
                    <p className="flex gap-2 text-sm text-muted-foreground">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                        If a report is in an approved or paid settlement, the
                        deletion is refused: cancel that settlement first.
                    </p>

                    {errors.length > 0 && (
                        <div className="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive-foreground">
                            {errors.map((message) => (
                                <p key={message}>{message}</p>
                            ))}
                        </div>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="confirm-mid">
                            Type <strong>{mid?.mid}</strong> to confirm
                        </Label>
                        <Input
                            id="confirm-mid"
                            value={form.data.confirm}
                            onChange={(e) =>
                                form.setData('confirm', e.target.value)
                            }
                            className="font-mono"
                            autoComplete="off"
                        />
                        <InputError message={form.errors.confirm} />
                    </div>

                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={!matches || form.processing}
                        >
                            {form.processing && <Spinner />}
                            Delete MID and history
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

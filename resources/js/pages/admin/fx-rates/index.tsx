import { Head, router, useForm } from '@inertiajs/react';
import { ArrowRightLeft, Plus, Trash2 } from 'lucide-react';
import { AffixInput, Field } from '@/components/admin/form';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { Pagination } from '@/components/admin/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate } from '@/lib/format';
import admin from '@/routes/admin';
import type { Paginated } from '@/types';

type Rate = {
    id: number;
    rate_date: string;
    base: string;
    quote: string;
    rate: string;
    source: string;
    created_by: string | null;
};

type Props = {
    rates: Paginated<Rate>;
    latest: Rate[];
    baseCurrency: string;
    currencies: string[];
    quotes: string[];
};

const today = () => new Date().toISOString().slice(0, 10);

export default function FxRates({
    rates,
    latest,
    baseCurrency,
    currencies,
    quotes,
}: Props) {
    const form = useForm({
        rate_date: today(),
        base: baseCurrency,
        quote: 'USDC',
        rate: '',
    });
    const { data, setData, errors } = form;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(admin.fxRates.store.url(), {
            preserveScroll: true,
            onSuccess: () => form.reset('rate'),
        });
    };

    return (
        <>
            <Head title="FX rates" />
            <PageBody>
                <PageHeader
                    title="FX rates"
                    description={`Used to convert MID currencies into ${baseCurrency} for dashboards and into USDC for settlements. Each report freezes the rate it used.`}
                />

                {latest.length > 0 && (
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        {latest.map((rate) => (
                            <div
                                key={rate.id}
                                className="rounded-xl border bg-card p-4 shadow-xs"
                            >
                                <p className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                                    {rate.base}
                                    <ArrowRightLeft className="size-3" />
                                    {rate.quote}
                                </p>
                                <p className="mt-1 font-mono text-2xl font-semibold tabular-nums">
                                    {Number(rate.rate).toString()}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    since {formatDate(rate.rate_date)}
                                </p>
                            </div>
                        ))}
                    </div>
                )}

                <form
                    onSubmit={submit}
                    className="grid gap-4 rounded-xl border bg-card p-5 shadow-xs sm:grid-cols-2 lg:grid-cols-[repeat(4,minmax(0,1fr))_auto] lg:items-end"
                >
                    <Field
                        label="Date"
                        htmlFor="rate_date"
                        error={errors.rate_date}
                    >
                        <Input
                            id="rate_date"
                            type="date"
                            max={today()}
                            value={data.rate_date}
                            onChange={(e) =>
                                setData('rate_date', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="From" error={errors.base}>
                        <Select
                            value={data.base}
                            onValueChange={(value) => setData('base', value)}
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {currencies.map((currency) => (
                                    <SelectItem key={currency} value={currency}>
                                        1 {currency}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field label="To" error={errors.quote}>
                        <Select
                            value={data.quote}
                            onValueChange={(value) => setData('quote', value)}
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {quotes
                                    .filter((quote) => quote !== data.base)
                                    .map((quote) => (
                                        <SelectItem key={quote} value={quote}>
                                            {quote}
                                        </SelectItem>
                                    ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field label="Rate" htmlFor="rate" error={errors.rate}>
                        <AffixInput
                            id="rate"
                            suffix={data.quote}
                            value={data.rate}
                            onChange={(e) => setData('rate', e.target.value)}
                            placeholder="1.16526"
                        />
                    </Field>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing ? <Spinner /> : <Plus />}
                        Save rate
                    </Button>
                </form>

                <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    {rates.data.length === 0 ? (
                        <p className="px-5 py-12 text-center text-sm text-muted-foreground">
                            No rates yet. Add the current EUR → USDC rate before
                            the first settlement.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="pl-4">Date</TableHead>
                                    <TableHead>Pair</TableHead>
                                    <TableHead className="text-right">
                                        Rate
                                    </TableHead>
                                    <TableHead className="hidden sm:table-cell">
                                        Added by
                                    </TableHead>
                                    <TableHead className="w-12 pr-4" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rates.data.map((rate) => (
                                    <TableRow key={rate.id}>
                                        <TableCell className="pl-4 tabular-nums">
                                            {formatDate(rate.rate_date)}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {rate.base} → {rate.quote}
                                        </TableCell>
                                        <TableCell className="text-right font-mono tabular-nums">
                                            {Number(rate.rate).toString()}
                                        </TableCell>
                                        <TableCell className="hidden text-muted-foreground sm:table-cell">
                                            {rate.created_by ?? rate.source}
                                        </TableCell>
                                        <TableCell className="pr-4 text-right">
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                aria-label="Remove rate"
                                                onClick={() => {
                                                    if (
                                                        confirm(
                                                            'Remove this rate?',
                                                        )
                                                    ) {
                                                        router.delete(
                                                            admin.fxRates.destroy.url(
                                                                rate.id,
                                                            ),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        );
                                                    }
                                                }}
                                            >
                                                <Trash2 />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                    <Pagination page={rates} noun="rates" />
                </div>
            </PageBody>
        </>
    );
}

FxRates.layout = {
    breadcrumbs: [{ title: 'FX rates', href: admin.fxRates.index() }],
};

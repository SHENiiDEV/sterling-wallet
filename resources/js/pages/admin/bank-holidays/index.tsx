import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    CalendarOff,
    ChevronLeft,
    ChevronRight,
    Plus,
    Trash2,
} from 'lucide-react';
import { Field } from '@/components/admin/form';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import admin from '@/routes/admin';

type Holiday = { id: number; date: string; name: string };

type Props = {
    year: number;
    holidays: Holiday[];
};

const weekday = new Intl.DateTimeFormat('en-GB', { weekday: 'short' });
const dayMonth = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'long',
});

export default function BankHolidays({ year, holidays }: Props) {
    const form = useForm({ date: '', name: '' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(admin.bankHolidays.store.url(), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    const today = new Date().toISOString().slice(0, 10);

    return (
        <>
            <Head title="Bank holidays" />
            <PageBody>
                <PageHeader
                    title="Bank holidays"
                    description="Acquirers don't publish reports on these days. The report for a holiday rolls into the next business day, just like weekends."
                />

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
                    <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
                        <header className="flex items-center justify-between border-b px-4 py-3">
                            <Button size="icon" variant="ghost" asChild>
                                <Link
                                    href={admin.bankHolidays.index({
                                        query: { year: year - 1 },
                                    })}
                                    aria-label="Previous year"
                                >
                                    <ChevronLeft />
                                </Link>
                            </Button>
                            <h2 className="text-sm font-semibold tabular-nums">
                                {year}
                            </h2>
                            <Button size="icon" variant="ghost" asChild>
                                <Link
                                    href={admin.bankHolidays.index({
                                        query: { year: year + 1 },
                                    })}
                                    aria-label="Next year"
                                >
                                    <ChevronRight />
                                </Link>
                            </Button>
                        </header>
                        {holidays.length === 0 ? (
                            <div className="flex flex-col items-center gap-2 px-6 py-14 text-center text-sm text-muted-foreground">
                                <CalendarOff className="size-5" />
                                No holidays for {year}.
                            </div>
                        ) : (
                            <ul className="divide-y">
                                {holidays.map((holiday) => {
                                    const date = new Date(
                                        `${holiday.date}T00:00:00`,
                                    );
                                    const past = holiday.date < today;

                                    return (
                                        <li
                                            key={holiday.id}
                                            className="flex items-center gap-4 px-4 py-3"
                                        >
                                            <div
                                                className={
                                                    past
                                                        ? 'flex size-12 flex-col items-center justify-center rounded-lg bg-muted text-muted-foreground'
                                                        : 'flex size-12 flex-col items-center justify-center rounded-lg bg-brand/10 text-brand'
                                                }
                                            >
                                                <span className="text-[10px] font-medium uppercase">
                                                    {weekday.format(date)}
                                                </span>
                                                <span className="text-lg leading-none font-semibold">
                                                    {date.getDate()}
                                                </span>
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate font-medium">
                                                    {holiday.name}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {dayMonth.format(date)}
                                                </p>
                                            </div>
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                aria-label={`Remove ${holiday.name}`}
                                                onClick={() =>
                                                    router.delete(
                                                        admin.bankHolidays.destroy.url(
                                                            holiday.id,
                                                        ),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </section>

                    <form
                        onSubmit={submit}
                        className="grid gap-4 rounded-xl border bg-card p-5 shadow-xs"
                    >
                        <h2 className="text-sm font-semibold">Add holiday</h2>
                        <Field
                            label="Date"
                            htmlFor="date"
                            error={form.errors.date}
                        >
                            <Input
                                id="date"
                                type="date"
                                value={form.data.date}
                                onChange={(e) =>
                                    form.setData('date', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Name"
                            htmlFor="name"
                            error={form.errors.name}
                        >
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                placeholder="August Bank Holiday"
                            />
                        </Field>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? <Spinner /> : <Plus />}
                            Add
                        </Button>
                    </form>
                </div>
            </PageBody>
        </>
    );
}

BankHolidays.layout = {
    breadcrumbs: [{ title: 'Bank holidays', href: admin.bankHolidays.index() }],
};

import InputError from '@/components/input-error';
import { StatusDot } from '@/components/admin/status-badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import type {
    CompanyRef,
    DocumentStatus,
    MerchantRef,
    Option,
    StaffMember,
} from '@/types';

export type DocumentFormData = {
    title: string;
    type: string;
    counterparty: string;
    owner_id: string;
    due_date: string;
    notes: string;
    company_id: string;
    merchant_id: string;
    document_status_id?: string;
};

const NONE = 'none';

export function DocumentFields({
    data,
    setData,
    errors,
    types,
    staff,
    statuses,
    companies,
    merchants,
}: {
    data: DocumentFormData;
    setData: <K extends keyof DocumentFormData>(
        key: K,
        value: DocumentFormData[K],
    ) => void;
    errors: Partial<Record<string, string>>;
    types: Option[];
    staff: StaffMember[];
    statuses?: DocumentStatus[];
    companies: CompanyRef[];
    merchants: MerchantRef[];
}) {
    const merchantOptions = data.company_id
        ? merchants.filter((m) => String(m.company_id) === data.company_id)
        : merchants;

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2 sm:col-span-2">
                <Label htmlFor="title">Title</Label>
                <Input
                    id="title"
                    value={data.title}
                    onChange={(e) => setData('title', e.target.value)}
                    placeholder="e.g. Merchant services agreement — Acme Ltd"
                    autoFocus
                />
                <InputError message={errors.title} />
            </div>

            <div className="grid gap-2">
                <Label>Type</Label>
                <Select
                    value={data.type}
                    onValueChange={(value) => setData('type', value)}
                >
                    <SelectTrigger className="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {types.map((type) => (
                            <SelectItem key={type.value} value={type.value}>
                                {type.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.type} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="counterparty">Counterparty</Label>
                <Input
                    id="counterparty"
                    value={data.counterparty}
                    onChange={(e) => setData('counterparty', e.target.value)}
                    placeholder="Company or merchant"
                />
                <InputError message={errors.counterparty} />
            </div>

            <div className="grid gap-2">
                <Label>Company</Label>
                <Select
                    value={data.company_id || NONE}
                    onValueChange={(value) => {
                        setData('company_id', value === NONE ? '' : value);
                        setData('merchant_id', '');
                    }}
                >
                    <SelectTrigger className="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={NONE}>Not linked</SelectItem>
                        {companies.map((company) => (
                            <SelectItem
                                key={company.id}
                                value={String(company.id)}
                            >
                                {company.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.company_id} />
            </div>

            <div className="grid gap-2">
                <Label>Merchant</Label>
                <Select
                    value={data.merchant_id || NONE}
                    onValueChange={(value) => {
                        const merchant = merchants.find(
                            (m) => String(m.id) === value,
                        );
                        setData('merchant_id', value === NONE ? '' : value);

                        if (merchant?.company_id && !data.company_id) {
                            setData('company_id', String(merchant.company_id));
                        }
                    }}
                >
                    <SelectTrigger className="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={NONE}>Not linked</SelectItem>
                        {merchantOptions.map((merchant) => (
                            <SelectItem
                                key={merchant.id}
                                value={String(merchant.id)}
                            >
                                {merchant.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.merchant_id} />
            </div>

            {statuses && (
                <div className="grid gap-2">
                    <Label>Status</Label>
                    <Select
                        value={data.document_status_id}
                        onValueChange={(value) =>
                            setData('document_status_id', value)
                        }
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {statuses.map((status) => (
                                <SelectItem
                                    key={status.id}
                                    value={String(status.id)}
                                >
                                    <StatusDot color={status.color} />
                                    {status.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.document_status_id} />
                </div>
            )}

            <div className="grid gap-2">
                <Label>Owner</Label>
                <Select
                    value={data.owner_id || NONE}
                    onValueChange={(value) =>
                        setData('owner_id', value === NONE ? '' : value)
                    }
                >
                    <SelectTrigger className="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={NONE}>Unassigned</SelectItem>
                        {staff.map((member) => (
                            <SelectItem
                                key={member.id}
                                value={String(member.id)}
                            >
                                {member.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.owner_id} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="due_date">Due date</Label>
                <Input
                    id="due_date"
                    type="date"
                    value={data.due_date}
                    onChange={(e) => setData('due_date', e.target.value)}
                />
                <InputError message={errors.due_date} />
            </div>

            <div className="grid gap-2 sm:col-span-2">
                <Label htmlFor="notes">Notes</Label>
                <Textarea
                    id="notes"
                    value={data.notes}
                    onChange={(e) => setData('notes', e.target.value)}
                    placeholder="Anything the team should know"
                    rows={3}
                />
                <InputError message={errors.notes} />
            </div>
        </div>
    );
}

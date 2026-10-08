import { router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { Badge, Button, Input, Select, type Tone } from '@/Components/ui';
import type { AttendanceFilterOptions, AttendanceFilterValues, DayStatus, Option, StatusOption } from '@/types';

const dayStatus: Record<DayStatus, [Tone, string]> = {
    PRESENT: ['success', 'Keldi'],
    PARTIAL: ['info', 'Qisman'],
    INCOMPLETE: ['warning', 'Yakunlanmagan'],
    LOCATION_REJECTED: ['danger', 'Joylashuv rad etildi'],
    ABSENT: ['secondary', 'Kelmadi'],
};

export function DayStatusBadge({ status }: { status: string | null }) {
    if (!status) {
        return <span className="text-muted">—</span>;
    }
    const [tone, label] = dayStatus[status as DayStatus] ?? ['secondary', status];

    return <Badge tone={tone}>{label}</Badge>;
}

export function duration(seconds: number): string {
    if (!seconds) {
        return '—';
    }
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);

    return hours > 0 ? `${hours} soat ${minutes} daq` : `${minutes} daq`;
}

type Props = {
    url: string;
    filters: AttendanceFilterValues;
    options: AttendanceFilterOptions;
    statuses: StatusOption[];
    range?: boolean;
    max?: string;
};

function OptionSelect({ label, value, options, onChange }: { label: string; value: number | null; options?: Option[]; onChange: (value: number | null) => void }) {
    if (!options || options.length === 0) {
        return null;
    }

    return (
        <Select className="lg:max-w-[13rem]" value={value ?? ''} onChange={(event) => onChange(event.target.value ? Number(event.target.value) : null)} aria-label={label}>
            <option value="">{label}: barchasi</option>
            {options.map((option) => (
                <option key={option.id} value={option.id}>
                    {option.name}
                </option>
            ))}
        </Select>
    );
}

export function AttendanceFilterBar({ url, filters, options, statuses, range = false, max }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');

    function visit(next: Partial<AttendanceFilterValues>) {
        const merged = { ...filters, search: search || null, ...next };
        const query = Object.fromEntries(Object.entries(merged).filter(([, value]) => value !== null && value !== '' && value !== undefined));
        router.get(url, query, { preserveState: true, preserveScroll: true, replace: true });
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        visit({});
    }

    const active = Boolean(
        filters.search || filters.faculty || filters.program || filters.course || filters.group || filters.internship || filters.organization || filters.supervisor || filters.status,
    );

    return (
        <form onSubmit={submit} className="grid gap-3 px-6 pb-4 sm:grid-cols-2 lg:flex lg:flex-wrap" role="search">
            {range ? (
                <>
                    <Input type="date" className="lg:max-w-[11rem]" value={filters.from ?? ''} max={max} onChange={(event) => visit({ from: event.target.value })} aria-label="Boshlanish sanasi" />
                    <Input type="date" className="lg:max-w-[11rem]" value={filters.to ?? ''} max={max} onChange={(event) => visit({ to: event.target.value })} aria-label="Tugash sanasi" />
                </>
            ) : (
                <Input type="date" className="lg:max-w-[11rem]" value={filters.date ?? ''} max={max} onChange={(event) => visit({ date: event.target.value })} aria-label="Sana" />
            )}
            <OptionSelect label="Fakultet" value={filters.faculty} options={options.faculties} onChange={(faculty) => visit({ faculty })} />
            <OptionSelect label="Yo‘nalish" value={filters.program} options={options.programs} onChange={(program) => visit({ program })} />
            <OptionSelect label="Kurs" value={filters.course} options={options.courses} onChange={(course) => visit({ course })} />
            <OptionSelect label="Guruh" value={filters.group} options={options.groups} onChange={(group) => visit({ group })} />
            <OptionSelect label="Amaliyot" value={filters.internship} options={options.internships} onChange={(internship) => visit({ internship })} />
            <OptionSelect label="Rahbar" value={filters.supervisor} options={options.supervisors} onChange={(supervisor) => visit({ supervisor })} />
            <OptionSelect label="Tashkilot" value={filters.organization} options={options.organizations} onChange={(organization) => visit({ organization })} />
            {!range ? (
                <Select className="lg:max-w-[13rem]" value={filters.status ?? ''} onChange={(event) => visit({ status: (event.target.value || null) as DayStatus | null })} aria-label="Holat">
                    <option value="">Holat: barchasi</option>
                    {statuses.map((status) => (
                        <option key={status.value} value={status.value}>
                            {status.label}
                        </option>
                    ))}
                </Select>
            ) : null}
            <Input className="lg:max-w-xs" placeholder="F.I.Sh., telefon yoki talaba ID" value={search} onChange={(event) => setSearch(event.target.value)} aria-label="Qidirish" />
            <div className="flex gap-2">
                <Button type="submit" variant="tonal" icon="search">
                    Qidirish
                </Button>
                {active ? (
                    <Button
                        variant="ghost"
                        onClick={() => router.get(url, range ? { from: filters.from, to: filters.to } : { date: filters.date }, { replace: true })}
                    >
                        Tozalash
                    </Button>
                ) : null}
            </div>
        </form>
    );
}

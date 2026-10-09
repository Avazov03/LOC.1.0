import { router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import Modal, { ModalBody, ModalFooter } from '@/Components/Modal';
import { Badge, Button, Field, Input, Select, Textarea, type Tone } from '@/Components/ui';
import type { AttendanceFilterOptions, AttendanceFilterValues, DayMark, DayStatus, Option, StatusOption } from '@/types';

const dayStatus: Record<DayStatus, [Tone, string]> = {
    PRESENT: ['success', 'Keldi'],
    INCOMPLETE: ['warning', 'Yakunlanmagan'],
    LOCATION_REJECTED: ['danger', 'Joylashuv rad etildi'],
    EXCUSED: ['info', 'Sababli'],
    ABSENT: ['secondary', 'Kelmadi'],
};

/**
 * Day status with the worked time next to "Keldi" (no minimum duration) and who decided it, if a supervisor marked it.
 */
export function DayStatusBadge({ status, seconds, mark }: { status: string | null; seconds?: number; mark?: DayMark | null }) {
    if (!status) {
        return <span className="text-muted">—</span>;
    }
    const [tone, label] = dayStatus[status as DayStatus] ?? ['secondary', status];
    const time = status === 'PRESENT' && seconds ? ` · ${duration(seconds)}` : '';

    return (
        <span className="inline-flex flex-col items-start gap-1">
            <Badge tone={tone}>{label + time}</Badge>
            {mark ? (
                <span className="max-w-[14rem] text-xs text-muted" title={mark.note ?? undefined}>
                    Rahbar belgiladi{mark.note ? `: ${mark.note}` : ''}
                </span>
            ) : null}
        </span>
    );
}

/**
 * "✅ Keldi" in one click, "Sababli" with a required reason, or revoking the existing mark.
 * The server re-checks scope and the date window; canMark only hides buttons that would be refused.
 */
export function DayMarkControls({ studentId, date, status, mark, canMark }: { studentId: number; date: string; status: string | null; mark: DayMark | null; canMark: boolean }) {
    const [excusing, setExcusing] = useState(false);
    const form = useForm({ date, kind: 'EXCUSED', note: '' });

    if (!canMark) {
        return null;
    }

    if (mark) {
        return (
            <Button
                size="sm"
                variant="ghost"
                onClick={() => window.confirm('Belgini bekor qilasizmi? Kun holati qayta hisoblanadi.') && router.post(`/attendance/marks/${mark.id}/revoke`, {}, { preserveScroll: true })}
            >
                Belgini bekor qilish
            </Button>
        );
    }

    if (status === 'PRESENT' || status === 'INCOMPLETE') {
        return null;
    }

    function excuse(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({ ...data, date }));
        form.post(`/attendance/students/${studentId}/marks`, { preserveScroll: true, onSuccess: () => { setExcusing(false); form.reset(); } });
    }

    return (
        <span className="inline-flex flex-wrap justify-end gap-2">
            <Button size="sm" icon="check" onClick={() => router.post(`/attendance/students/${studentId}/marks`, { date, kind: 'PRESENT' }, { preserveScroll: true })}>
                Keldi
            </Button>
            <Button size="sm" variant="secondary" onClick={() => setExcusing(true)}>
                Sababli
            </Button>
            <Modal title={`Sababli · ${date}`} open={excusing} onClose={() => setExcusing(false)}>
                <form onSubmit={excuse} noValidate>
                    <ModalBody>
                        <Field label="Sabab" htmlFor={`note-${studentId}-${date}`} error={form.errors.note}>
                            <Textarea
                                id={`note-${studentId}-${date}`}
                                value={form.data.note}
                                onChange={(event) => form.setData('note', event.target.value)}
                                placeholder="Masalan: kasal, ma’lumotnoma bor"
                            />
                        </Field>
                        <p className="text-sm text-muted">Sababli kun «Kelmadi» hisoblanmaydi. Belgi audit jurnaliga yoziladi va keyin bekor qilinishi mumkin.</p>
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="secondary" onClick={() => setExcusing(false)}>
                            Bekor
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Saqlash
                        </Button>
                    </ModalFooter>
                </form>
            </Modal>
        </span>
    );
}

export const WEEKDAYS: Array<{ day: number; label: string }> = [
    { day: 1, label: 'Du' },
    { day: 2, label: 'Se' },
    { day: 3, label: 'Chor' },
    { day: 4, label: 'Pay' },
    { day: 5, label: 'Ju' },
    { day: 6, label: 'Sha' },
    { day: 7, label: 'Yak' },
];

const PRESETS: Array<{ label: string; days: number[] }> = [
    { label: 'Har kuni', days: [1, 2, 3, 4, 5, 6, 7] },
    { label: 'Du–Sha', days: [1, 2, 3, 4, 5, 6] },
    { label: 'Du–Ju', days: [1, 2, 3, 4, 5] },
    { label: 'Toq kunlar', days: [1, 3, 5] },
    { label: 'Juft kunlar', days: [2, 4, 6] },
];

/**
 * Weekday picker with presets: every day, Mon–Sat, Mon–Fri, odd (Mon/Wed/Fri), even (Tue/Thu/Sat), or any custom set.
 */
export function WorkDaysPicker({ value, onChange, error }: { value: number[]; onChange: (days: number[]) => void; error?: string }) {
    const same = (days: number[]) => days.length === value.length && days.every((day) => value.includes(day));

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap gap-2">
                {PRESETS.map((preset) => (
                    <Button key={preset.label} size="sm" variant={same(preset.days) ? 'primary' : 'secondary'} onClick={() => onChange(preset.days)}>
                        {preset.label}
                    </Button>
                ))}
            </div>
            <div className="flex flex-wrap gap-3" role="group" aria-label="Hafta kunlari">
                {WEEKDAYS.map(({ day, label }) => (
                    <label key={day} className="inline-flex items-center gap-1.5 text-sm text-heading">
                        <input
                            type="checkbox"
                            className="size-4 accent-primary-500"
                            checked={value.includes(day)}
                            onChange={() => onChange(value.includes(day) ? value.filter((d) => d !== day) : [...value, day].sort((a, b) => a - b))}
                        />
                        {label}
                    </label>
                ))}
            </div>
            {error ? <p className="text-sm text-danger">{error}</p> : null}
        </div>
    );
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

import { router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import Modal, { ModalBody, ModalFooter } from '@/Components/Modal';
import { Badge, Button, Card, CardHeader, EmptyRow, Field, Input, Select, Table, Td } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';
import type { Option } from '@/types';

type Rules = {
    check_in_enabled: boolean;
    check_out_enabled: boolean;
    minimum_duration_minutes: number | null;
    multiple_sessions_allowed: boolean;
    location_required: boolean;
    accuracy_threshold_meters: number | null;
    manual_correction_allowed: boolean;
};
type Override = Rules & { id: number; group_id: number; group: string | null; program: string | null; course: string | null };

const toggles: Array<{ key: keyof Rules; label: string; hint: string }> = [
    { key: 'check_in_enabled', label: 'Kelishni qayd etish', hint: '«Amaliyotni boshlash» tugmasi ishlaydi.' },
    { key: 'check_out_enabled', label: 'Ketishni qayd etish', hint: 'O‘chirilsa, kelish yozuvining o‘zi yakunlangan sessiya hisoblanadi.' },
    { key: 'location_required', label: 'Joylashuv majburiy', hint: 'O‘chirish — admin tomonidan audit qilinadigan istisno.' },
    { key: 'manual_correction_allowed', label: 'Qo‘lda tuzatishga ruxsat', hint: 'Admin tuzatish kiritishi mumkin.' },
];

function RulesFields({ data, setData, errors }: { data: Rules; setData: (key: keyof Rules, value: boolean | number | null) => void; errors: Partial<Record<keyof Rules, string>> }) {
    return (
        <div className="grid gap-5">
            <div className="grid gap-3">
                {toggles.map((toggle) => (
                    <label key={toggle.key} className="flex items-start gap-3">
                        <input
                            type="checkbox"
                            className="mt-1 size-4 accent-primary-500"
                            checked={Boolean(data[toggle.key])}
                            onChange={(event) => setData(toggle.key, event.target.checked)}
                        />
                        <span>
                            <span className="block font-medium text-heading">{toggle.label}</span>
                            <span className="block text-sm text-muted">{toggle.hint}</span>
                        </span>
                    </label>
                ))}
                <label className="flex items-start gap-3 opacity-70">
                    <input type="checkbox" className="mt-1 size-4" checked={false} disabled readOnly />
                    <span>
                        <span className="block font-medium text-heading">Bir kunda bir nechta sessiya</span>
                        <span className="block text-sm text-muted">Qulflangan: bir kunda faqat bitta sessiya, ikkinchi kelish rad etiladi.</span>
                    </span>
                </label>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Minimal davomiylik (daqiqa)" htmlFor="minimum" error={errors.minimum_duration_minutes}>
                    <Input
                        id="minimum"
                        type="number"
                        min={1}
                        placeholder="O‘chiq"
                        value={data.minimum_duration_minutes ?? ''}
                        onChange={(event) => setData('minimum_duration_minutes', event.target.value ? Number(event.target.value) : null)}
                    />
                </Field>
                <Field label="Aniqlik chegarasi (metr)" htmlFor="accuracy" error={errors.accuracy_threshold_meters}>
                    <Input
                        id="accuracy"
                        type="number"
                        min={5}
                        placeholder="O‘chiq"
                        value={data.accuracy_threshold_meters ?? ''}
                        onChange={(event) => setData('accuracy_threshold_meters', event.target.value ? Number(event.target.value) : null)}
                    />
                </Field>
            </div>
        </div>
    );
}

function summary(rules: Rules): string {
    return [
        rules.minimum_duration_minutes ? `min ${rules.minimum_duration_minutes} daq` : 'min o‘chiq',
        rules.location_required ? 'joylashuv majburiy' : 'joylashuvsiz',
        rules.accuracy_threshold_meters ? `aniqlik ≤ ${rules.accuracy_threshold_meters} m` : 'aniqlik o‘chiq',
        rules.check_in_enabled ? null : 'kelish o‘chiq',
        rules.check_out_enabled ? null : 'ketish o‘chiq',
        rules.manual_correction_allowed ? null : 'tuzatish taqiqlangan',
    ]
        .filter(Boolean)
        .join(' · ');
}

export default function Policies({ university, overrides, groups }: { university: Rules; overrides: Override[]; groups: Option[] }) {
    const form = useForm<Rules>(university);
    const [editing, setEditing] = useState<{ group_id: number | '' } | null>(null);
    const group = useForm<Rules & { group_id: number | '' }>({ ...university, group_id: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put('/attendance/policies/university', { preserveScroll: true });
    }

    function openGroup(override?: Override) {
        group.clearErrors();
        group.setData(override ? { ...override, group_id: override.group_id } : { ...university, group_id: '' });
        setEditing({ group_id: override?.group_id ?? '' });
    }

    function submitGroup(event: FormEvent) {
        event.preventDefault();
        group.post('/attendance/policies/groups', { preserveScroll: true, onSuccess: () => setEditing(null) });
    }

    return (
        <AppLayout title="Davomat siyosati">
            <div className="space-y-6">
                <Card>
                    <CardHeader
                        title="Universitet siyosati"
                        description="Guruh uchun alohida siyosat bo‘lmasa, shu qoidalar ishlaydi. Guruh siyosati universitet siyosatini to‘liq almashtiradi."
                    />
                    <form onSubmit={submit} noValidate className="grid gap-5 px-6 pb-6 md:max-w-2xl">
                        <RulesFields data={form.data} setData={(key, value) => form.setData(key, value as never)} errors={form.errors} />
                        {!form.data.location_required ? (
                            <p className="rounded-md bg-warning/15 px-4 py-3 text-sm text-[#e09600] dark:text-warning">
                                Joylashuv o‘chirilsa, davomat geozonasiz qabul qilinadi. Bu o‘zgarish audit jurnalida alohida belgilanadi.
                            </p>
                        ) : null}
                        <div>
                            <Button type="submit" disabled={form.processing || !form.isDirty}>Saqlash</Button>
                        </div>
                    </form>
                </Card>

                <Card>
                    <CardHeader title="Guruh siyosatlari" action={<Button icon="plus" onClick={() => openGroup()}>Guruh siyosati</Button>} />
                    <Table head={['Guruh', 'Qoidalar', '']}>
                        {overrides.length === 0 ? (
                            <EmptyRow colSpan={3}>Guruh siyosati yo‘q. Barcha guruhlar universitet siyosatiga bo‘ysunadi.</EmptyRow>
                        ) : (
                            overrides.map((override) => (
                                <tr key={override.id}>
                                    <Td className="font-medium text-heading">
                                        {override.group}
                                        <span className="block text-xs font-normal text-muted">{[override.program, override.course].filter(Boolean).join(' · ')}</span>
                                    </Td>
                                    <Td className="text-sm">
                                        {summary(override)}
                                        {!override.location_required ? <span className="ml-2"><Badge tone="warning">istisno</Badge></span> : null}
                                    </Td>
                                    <Td className="text-right whitespace-nowrap">
                                        <div className="flex justify-end gap-2">
                                            <Button size="sm" variant="tonal" icon="pencil" onClick={() => openGroup(override)}>Tahrirlash</Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => {
                                                    if (window.confirm('Guruh siyosatini o‘chirasizmi? Guruh universitet siyosatiga qaytadi.')) {
                                                        router.post(`/attendance/policies/${override.id}/deactivate`, {}, { preserveScroll: true });
                                                    }
                                                }}
                                            >
                                                O‘chirish
                                            </Button>
                                        </div>
                                    </Td>
                                </tr>
                            ))
                        )}
                    </Table>
                    <div className="h-2" />
                </Card>
            </div>

            <Modal title="Guruh siyosati" open={editing !== null} onClose={() => setEditing(null)}>
                <form onSubmit={submitGroup} noValidate>
                    <ModalBody>
                        <Field label="Guruh" htmlFor="group" error={group.errors.group_id}>
                            <Select id="group" value={group.data.group_id} disabled={editing?.group_id !== ''} onChange={(event) => group.setData('group_id', event.target.value ? Number(event.target.value) : '')}>
                                <option value="">Guruhni tanlang</option>
                                {groups.map((option) => (
                                    <option key={option.id} value={option.id}>{option.name}</option>
                                ))}
                            </Select>
                        </Field>
                        <RulesFields data={group.data} setData={(key, value) => group.setData(key, value as never)} errors={group.errors} />
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="secondary" onClick={() => setEditing(null)}>Bekor qilish</Button>
                        <Button type="submit" disabled={group.processing || group.data.group_id === ''}>Saqlash</Button>
                    </ModalFooter>
                </form>
            </Modal>
        </AppLayout>
    );
}

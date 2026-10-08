import { Link, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useMemo, useState } from 'react';
import Modal, { ModalBody, ModalFooter } from '@/Components/Modal';
import { Button, Card, CardHeader, EmptyRow, Field, Input, Select, StatusBadge, Table, Td } from '@/Components/ui';
import type { OrganizationOption, Participant, SharedProps } from '@/types';

type Props = {
    internshipId: number;
    periodStart: string;
    periodEnd: string;
    participants: Participant[];
    organizations: OrganizationOption[];
    studentHref?: (id: number) => string;
};

/**
 * Participant list with multi-select and bulk assignment (§21, §22). The server validates every student again.
 */
export default function ParticipantAssign({ internshipId, periodStart, periodEnd, participants, organizations, studentHref }: Props) {
    const { flash } = usePage<SharedProps>().props;
    const [selected, setSelected] = useState<number[]>([]);
    const [open, setOpen] = useState(false);
    const form = useForm<{ internship_id: number; organization_id: number | ''; student_ids: number[]; start_date: string; end_date: string }>({
        internship_id: internshipId,
        organization_id: organizations[0]?.id ?? '',
        student_ids: [],
        start_date: periodStart,
        end_date: periodEnd,
    });

    const assignable = useMemo(() => participants.filter((participant) => participant.assignment === null).map((participant) => participant.id), [participants]);
    const names = useMemo(() => new Map(participants.map((participant) => [participant.id, participant.name])), [participants]);
    const allSelected = assignable.length > 0 && assignable.every((id) => selected.includes(id));

    function toggle(id: number) {
        setSelected((current) => (current.includes(id) ? current.filter((value) => value !== id) : [...current, id]));
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({ ...data, student_ids: selected }));
        form.post('/assignments', {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                setSelected([]);
            },
        });
    }

    const failures = (flash.assignment_results ?? []).filter((row) => row.error !== null);

    return (
        <Card>
            <CardHeader
                title={`Talabalar (${participants.length})`}
                description="Biriktirilmagan talabalarni belgilang va bitta tashkilotga biriktiring. Har bir talaba alohida tekshiriladi."
                action={
                    <Button icon="link" disabled={selected.length === 0 || organizations.length === 0} onClick={() => setOpen(true)}>
                        Biriktirish ({selected.length})
                    </Button>
                }
            />
            {failures.length > 0 ? (
                <div className="mx-6 mb-4 rounded-md bg-danger/10 px-4 py-3 text-sm text-danger" role="alert">
                    <p className="font-medium">Rad etilgan talabalar:</p>
                    <ul className="mt-1 list-disc pl-5">
                        {failures.map((row) => (
                            <li key={row.student_id}>
                                {names.get(row.student_id) ?? `#${row.student_id}`}: {row.error}
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}
            <Table
                head={[
                    <input
                        key="all"
                        type="checkbox"
                        className="size-4 accent-primary-500"
                        checked={allSelected}
                        disabled={assignable.length === 0}
                        onChange={() => setSelected(allSelected ? [] : assignable)}
                        aria-label="Barcha biriktirilmaganlarni tanlash"
                    />,
                    'Talaba',
                    'Telefon',
                    'Joriy tashkilot',
                    'Muddat',
                    'Holat',
                ]}
            >
                {participants.length === 0 ? (
                    <EmptyRow colSpan={6}>Hali talaba qo‘shilmagan. Talabalar taklif havolasi orqali Telegram botda ro‘yxatdan o‘tadi.</EmptyRow>
                ) : (
                    participants.map((participant) => (
                        <tr key={participant.id}>
                            <Td>
                                <input
                                    type="checkbox"
                                    className="size-4 accent-primary-500"
                                    checked={selected.includes(participant.id)}
                                    disabled={participant.assignment !== null}
                                    onChange={() => toggle(participant.id)}
                                    aria-label={`${participant.name} ni tanlash`}
                                />
                            </Td>
                            <Td className="font-medium text-heading">
                                {studentHref ? (
                                    <Link href={studentHref(participant.id)} className="hover:text-primary-600">
                                        {participant.name}
                                    </Link>
                                ) : (
                                    participant.name
                                )}
                                {participant.student_code ? <span className="block text-xs font-normal text-muted">{participant.student_code}</span> : null}
                            </Td>
                            <Td>{participant.phone}</Td>
                            <Td>{participant.assignment?.organization ?? <span className="text-muted">Biriktirilmagan</span>}</Td>
                            <Td className="whitespace-nowrap text-sm">
                                {participant.assignment ? `${participant.assignment.start_at} — ${participant.assignment.end_at}` : '—'}
                            </Td>
                            <Td>{participant.assignment ? <StatusBadge status={participant.assignment.status} /> : null}</Td>
                        </tr>
                    ))
                )}
            </Table>
            <div className="h-2" />

            <Modal title={`${selected.length} ta talabani biriktirish`} open={open} onClose={() => setOpen(false)}>
                <form onSubmit={submit} noValidate>
                    <ModalBody>
                        <Field label="Tashkilot (faqat faollar)" htmlFor="organization_id" error={form.errors.organization_id}>
                            <Select id="organization_id" value={form.data.organization_id} onChange={(event) => form.setData('organization_id', Number(event.target.value))}>
                                {organizations.map((organization) => (
                                    <option key={organization.id} value={organization.id}>
                                        {organization.name} — {organization.address}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Boshlanish" htmlFor="start_date" error={form.errors.start_date}>
                                <Input id="start_date" type="date" value={form.data.start_date} onChange={(event) => form.setData('start_date', event.target.value)} />
                            </Field>
                            <Field label="Tugash" htmlFor="end_date" error={form.errors.end_date}>
                                <Input id="end_date" type="date" value={form.data.end_date} onChange={(event) => form.setData('end_date', event.target.value)} />
                            </Field>
                        </div>
                        {form.errors.student_ids ? <p className="text-sm text-danger">{form.errors.student_ids}</p> : null}
                        <p className="text-sm text-muted">Boshlanish sanasi bugun yoki o‘tgan bo‘lsa, biriktirish darhol faol bo‘ladi. Kelajakdagi sana bo‘lsa, kutilayotgan holatda turadi.</p>
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="secondary" onClick={() => setOpen(false)}>
                            Bekor
                        </Button>
                        <Button type="submit" disabled={form.processing || form.data.organization_id === ''}>
                            Biriktirish
                        </Button>
                    </ModalFooter>
                </form>
            </Modal>
        </Card>
    );
}

import { Link, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import Modal, { ModalBody, ModalFooter } from '@/Components/Modal';
import { Button, Card, CardHeader, EmptyRow, Field, Input, Pagination, Select, Table, Td } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';
import type { Option, Paginated } from '@/types';

type Row = {
    id: number;
    group: string;
    program: string;
    course: string;
    year: string;
    period_start: string;
    period_end: string;
    supervisor: string | null;
    participants_count: number;
    active_assignments_count: number;
};

type SupervisorOption = { id: number; name: string; position: string };

export default function InternshipsIndex({ internships, groups, supervisors }: { internships: Paginated<Row>; groups: Option[]; supervisors: SupervisorOption[] }) {
    const [open, setOpen] = useState(false);
    const form = useForm<{ student_group_id: number | ''; supervisor_profile_id: number | ''; period_start: string; period_end: string }>({
        student_group_id: groups[0]?.id ?? '',
        supervisor_profile_id: supervisors[0]?.id ?? '',
        period_start: '',
        period_end: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/internships', { onSuccess: () => setOpen(false) });
    }

    const blocked = groups.length === 0 || supervisors.length === 0;

    return (
        <AppLayout title="Amaliyot guruhlari">
            <Card>
                <CardHeader
                    title="Amaliyot guruhlari"
                    description={blocked ? 'Avval akademik guruh va faol rahbar qo‘shing.' : 'Har bir amaliyot guruhi: bitta akademik guruh, o‘quv yili, muddat va rahbar.'}
                    action={
                        <Button icon="plus" disabled={blocked} onClick={() => setOpen(true)}>
                            Yangi amaliyot guruhi
                        </Button>
                    }
                />
                <Table head={['Guruh', 'Yo‘nalish / kurs', 'O‘quv yili', 'Muddat', 'Rahbar', 'Talabalar', 'Faol biriktirish']}>
                    {internships.data.length === 0 ? (
                        <EmptyRow colSpan={7}>Amaliyot guruhi yo‘q.</EmptyRow>
                    ) : (
                        internships.data.map((row) => (
                            <tr key={row.id}>
                                <Td className="font-medium text-heading">
                                    <Link href={`/internships/${row.id}`} className="hover:text-primary-600">
                                        {row.group}
                                    </Link>
                                </Td>
                                <Td>
                                    {row.program}
                                    <span className="block text-xs text-muted">{row.course}</span>
                                </Td>
                                <Td>{row.year}</Td>
                                <Td className="whitespace-nowrap">
                                    {row.period_start} — {row.period_end}
                                </Td>
                                <Td>{row.supervisor ?? '—'}</Td>
                                <Td>{row.participants_count}</Td>
                                <Td>{row.active_assignments_count}</Td>
                            </tr>
                        ))
                    )}
                </Table>
                <Pagination page={internships} />
            </Card>

            <Modal title="Yangi amaliyot guruhi" open={open} onClose={() => setOpen(false)}>
                <form onSubmit={submit} noValidate>
                    <ModalBody>
                        <Field label="Akademik guruh" htmlFor="student_group_id" error={form.errors.student_group_id}>
                            <Select id="student_group_id" value={form.data.student_group_id} onChange={(event) => form.setData('student_group_id', Number(event.target.value))}>
                                {groups.map((group) => (
                                    <option key={group.id} value={group.id}>
                                        {group.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Rahbar (faqat faollar)" htmlFor="supervisor_profile_id" error={form.errors.supervisor_profile_id}>
                            <Select id="supervisor_profile_id" value={form.data.supervisor_profile_id} onChange={(event) => form.setData('supervisor_profile_id', Number(event.target.value))}>
                                {supervisors.map((supervisor) => (
                                    <option key={supervisor.id} value={supervisor.id}>
                                        {supervisor.name} — {supervisor.position}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Boshlanish" htmlFor="period_start" error={form.errors.period_start}>
                                <Input id="period_start" type="date" value={form.data.period_start} onChange={(event) => form.setData('period_start', event.target.value)} />
                            </Field>
                            <Field label="Tugash" htmlFor="period_end" error={form.errors.period_end}>
                                <Input id="period_end" type="date" value={form.data.period_end} onChange={(event) => form.setData('period_end', event.target.value)} />
                            </Field>
                        </div>
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="secondary" onClick={() => setOpen(false)}>
                            Bekor
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Yaratish
                        </Button>
                    </ModalFooter>
                </form>
            </Modal>
        </AppLayout>
    );
}

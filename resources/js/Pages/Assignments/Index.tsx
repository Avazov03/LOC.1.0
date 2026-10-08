import { Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import Modal, { ModalBody, ModalFooter } from '@/Components/Modal';
import { Button, Card, CardHeader, EmptyRow, Field, Input, Pagination, Select, StatusBadge, Table, Td, Textarea } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';
import type { Assignment, Option, OrganizationOption, Paginated } from '@/types';

type Filters = { status: string | null; internship: number | null; organization: number | null };

export default function AssignmentsIndex({
    assignments,
    filters,
    organizations,
    internships,
}: {
    assignments: Paginated<Assignment>;
    filters: Filters;
    organizations: OrganizationOption[];
    internships: Option[];
}) {
    const [cancelling, setCancelling] = useState<Assignment | null>(null);
    const cancelForm = useForm<{ reason: string }>({ reason: '' });
    const [editing, setEditing] = useState<Assignment | null>(null);
    const editForm = useForm<{ start_date: string; end_date: string }>({ start_date: '', end_date: '' });

    function openEdit(assignment: Assignment) {
        editForm.clearErrors();
        editForm.setData({ start_date: assignment.start_at?.slice(0, 10) ?? '', end_date: assignment.end_at?.slice(0, 10) ?? '' });
        setEditing(assignment);
    }

    function saveEdit(event: FormEvent) {
        event.preventDefault();
        if (!editing) {
            return;
        }
        editForm.transform((data) => (editing.status === 'PENDING' ? data : { end_date: data.end_date }));
        editForm.put(`/assignments/${editing.id}`, { preserveScroll: true, onSuccess: () => setEditing(null) });
    }

    function filter(key: keyof Filters, value: string) {
        router.get('/assignments', { ...filters, [key]: value || undefined }, { preserveState: true, replace: true });
    }

    function act(assignment: Assignment, action: 'activate' | 'end') {
        const question = action === 'end' ? 'Biriktirishni yakunlaysizmi? Tarix saqlanadi.' : 'Biriktirishni faollashtirasizmi?';
        if (window.confirm(question)) {
            router.post(`/assignments/${assignment.id}/${action}`, {}, { preserveScroll: true });
        }
    }

    function cancel(event: FormEvent) {
        event.preventDefault();
        if (!cancelling) {
            return;
        }
        cancelForm.post(`/assignments/${cancelling.id}/cancel`, {
            preserveScroll: true,
            onSuccess: () => {
                setCancelling(null);
                cancelForm.reset();
            },
        });
    }

    return (
        <AppLayout title="Biriktirishlar">
            <Card>
                <CardHeader title="Biriktirishlar" description="Talabada bir vaqtda faqat bitta kutilayotgan yoki faol biriktirish bo‘ladi. Yangi biriktirish amaliyot guruhi sahifasidan yaratiladi." />
                <div className="flex flex-wrap gap-3 px-6 pb-4">
                    <Select className="max-w-[12rem]" value={filters.status ?? ''} onChange={(event) => filter('status', event.target.value)} aria-label="Holat">
                        <option value="">Barcha holatlar</option>
                        <option value="PENDING">Kutilmoqda</option>
                        <option value="ACTIVE">Faol</option>
                        <option value="ENDED">Yakunlangan</option>
                        <option value="CANCELLED">Bekor qilingan</option>
                    </Select>
                    <Select className="max-w-xs" value={filters.internship ?? ''} onChange={(event) => filter('internship', event.target.value)} aria-label="Amaliyot guruhi">
                        <option value="">Barcha guruhlar</option>
                        {internships.map((internship) => (
                            <option key={internship.id} value={internship.id}>
                                {internship.name}
                            </option>
                        ))}
                    </Select>
                    <Select className="max-w-xs" value={filters.organization ?? ''} onChange={(event) => filter('organization', event.target.value)} aria-label="Tashkilot">
                        <option value="">Barcha tashkilotlar</option>
                        {organizations.map((organization) => (
                            <option key={organization.id} value={organization.id}>
                                {organization.name}
                            </option>
                        ))}
                    </Select>
                </div>
                <Table head={['Talaba', 'Guruh', 'Tashkilot', 'Rahbar', 'Muddat', 'Holat', '']}>
                    {assignments.data.length === 0 ? (
                        <EmptyRow colSpan={7}>Biriktirish topilmadi.</EmptyRow>
                    ) : (
                        assignments.data.map((assignment) => (
                            <tr key={assignment.id}>
                                <Td className="font-medium text-heading">
                                    <Link href={`/academic/students/${assignment.student_id}`} className="hover:text-primary-600">
                                        {assignment.student}
                                    </Link>
                                </Td>
                                <Td>{assignment.group}</Td>
                                <Td>{assignment.organization}</Td>
                                <Td>{assignment.supervisor}</Td>
                                <Td className="whitespace-nowrap text-sm">
                                    {assignment.start_at} — {assignment.end_at}
                                    {assignment.ended_at ? <span className="block text-xs text-muted">Yakunlandi: {assignment.ended_at}</span> : null}
                                    {assignment.cancel_reason ? <span className="block text-xs text-muted">Sabab: {assignment.cancel_reason}</span> : null}
                                </Td>
                                <Td>
                                    <StatusBadge status={assignment.status} />
                                </Td>
                                <Td className="text-right whitespace-nowrap">
                                    {assignment.status === 'PENDING' || assignment.status === 'ACTIVE' ? (
                                        <Button size="sm" variant="ghost" icon="pencil" onClick={() => openEdit(assignment)} aria-label="Muddatni tahrirlash" title="Muddatni tahrirlash" />
                                    ) : null}{' '}
                                    {assignment.status === 'PENDING' ? (
                                        <Button size="sm" variant="tonal" onClick={() => act(assignment, 'activate')}>
                                            Faollashtirish
                                        </Button>
                                    ) : null}
                                    {assignment.status === 'ACTIVE' ? (
                                        <Button size="sm" variant="tonal" onClick={() => act(assignment, 'end')}>
                                            Yakunlash
                                        </Button>
                                    ) : null}{' '}
                                    {assignment.status === 'PENDING' || assignment.status === 'ACTIVE' ? (
                                        <Button size="sm" variant="secondary" onClick={() => setCancelling(assignment)}>
                                            Bekor qilish
                                        </Button>
                                    ) : null}
                                </Td>
                            </tr>
                        ))
                    )}
                </Table>
                <Pagination page={assignments} />
            </Card>

            <Modal title="Biriktirish muddati" open={editing !== null} onClose={() => setEditing(null)}>
                <form onSubmit={saveEdit} noValidate>
                    <ModalBody>
                        <p className="text-sm text-muted">
                            {editing?.status === 'ACTIVE'
                                ? 'Faol biriktirishda faqat tugash sanasini o‘zgartirish mumkin. Tashkilotni almashtirish uchun joy o‘zgartirish so‘rovidan foydalaning.'
                                : 'Kutilayotgan biriktirishning boshlanish va tugash sanasini o‘zgartirish mumkin. Boshlanish bugun yoki o‘tgan kun bo‘lsa, biriktirish darhol faollashadi.'}
                        </p>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Boshlanish" htmlFor="start_date" error={editForm.errors.start_date}>
                                <Input id="start_date" type="date" value={editForm.data.start_date} disabled={editing?.status !== 'PENDING'} onChange={(event) => editForm.setData('start_date', event.target.value)} />
                            </Field>
                            <Field label="Tugash" htmlFor="end_date" error={editForm.errors.end_date}>
                                <Input id="end_date" type="date" value={editForm.data.end_date} onChange={(event) => editForm.setData('end_date', event.target.value)} />
                            </Field>
                        </div>
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="secondary" onClick={() => setEditing(null)}>
                            Yopish
                        </Button>
                        <Button type="submit" disabled={editForm.processing}>
                            Saqlash
                        </Button>
                    </ModalFooter>
                </form>
            </Modal>

            <Modal title="Biriktirishni bekor qilish" open={cancelling !== null} onClose={() => setCancelling(null)}>
                <form onSubmit={cancel} noValidate>
                    <ModalBody>
                        <p className="text-sm text-muted">Bekor qilish — umuman kuchga kirmasligi kerak bo‘lgan biriktirish uchun. Haqiqiy amaliyotni tugatish uchun “Yakunlash”ni tanlang.</p>
                        <Field label="Sabab" htmlFor="reason" error={cancelForm.errors.reason}>
                            <Textarea id="reason" value={cancelForm.data.reason} onChange={(event) => cancelForm.setData('reason', event.target.value)} />
                        </Field>
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="secondary" onClick={() => setCancelling(null)}>
                            Yopish
                        </Button>
                        <Button type="submit" disabled={cancelForm.processing}>
                            Bekor qilish
                        </Button>
                    </ModalFooter>
                </form>
            </Modal>
        </AppLayout>
    );
}

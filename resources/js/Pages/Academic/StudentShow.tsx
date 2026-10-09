import { Link, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import AuditTable from '@/Components/AuditTable';
import ChangeRequestTable from '@/Components/ChangeRequestTable';
import Icon from '@/Components/Icon';
import Modal, { ModalBody, ModalFooter } from '@/Components/Modal';
import { StudentTelegramLink, StudentTelegramStatus } from '@/Components/StudentTelegramRebind';
import { Button, Card, CardHeader, Dl, EmptyRow, Field, Input, Select, StatusBadge, Table, Td, Textarea } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';
import type { Assignment, AuditEntry, ChangeRequest, StudentDetail } from '@/types';

export default function StudentShow({
    student,
    assignments,
    changeRequests,
    history,
}: {
    student: StudentDetail;
    assignments: Assignment[];
    changeRequests: ChangeRequest[];
    history: AuditEntry[];
}) {
    const [editing, setEditing] = useState(false);
    const [changingStatus, setChangingStatus] = useState(false);
    const open = assignments.find((assignment) => assignment.status === 'ACTIVE' || assignment.status === 'PENDING') ?? null;

    const edit = useForm({
        first_name: student.first_name,
        last_name: student.last_name,
        phone: student.phone,
        student_code: student.student_code ?? '',
        reason: '',
    });
    const status = useForm({ status: student.status, reason: '' });

    function submitEdit(event: FormEvent) {
        event.preventDefault();
        edit.put(`/academic/students/${student.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                setEditing(false);
                edit.setData('reason', '');
            },
        });
    }

    function submitStatus(event: FormEvent) {
        event.preventDefault();
        status.patch(`/academic/students/${student.id}/status`, {
            preserveScroll: true,
            onSuccess: () => {
                setChangingStatus(false);
                status.setData('reason', '');
            },
        });
    }

    return (
        <AppLayout title={student.name}>
            <div className="mb-4">
                <Link href="/academic/students" className="inline-flex items-center gap-1 text-sm text-muted hover:text-primary-600">
                    <Icon name="chevronLeft" className="size-4" />
                    Talabalar
                </Link>
            </div>
            <div className="space-y-6">
                <Card>
                    <CardHeader
                        title={student.name}
                        description={[student.faculty, student.program, student.course, student.group].filter(Boolean).join(' · ') || undefined}
                        action={
                            <div className="flex flex-wrap gap-2">
                                <Link
                                    href={`/attendance/students/${student.id}`}
                                    className="inline-flex items-center gap-1.5 rounded-md bg-primary-500 px-5 py-2 text-[0.9375rem] font-medium text-white hover:bg-primary-600"
                                >
                                    <Icon name="clock" className="size-[1.125rem]" />
                                    Davomat
                                </Link>
                                <Button variant="tonal" icon="pencil" onClick={() => setEditing(true)}>
                                    Tuzatish
                                </Button>
                                <Button variant="secondary" icon="shield" onClick={() => setChangingStatus(true)}>
                                    Holatni o‘zgartirish
                                </Button>
                            </div>
                        }
                    />
                    <Dl
                        items={[
                            ['Telefon', student.phone],
                            ['Talaba ID', student.student_code],
                            ['Holat', <StatusBadge key="s" status={student.status} />],
                            ['Telegram ID', student.telegram_user_id || '—'],
                            ['Telegram', <StudentTelegramStatus key="tg" studentId={student.id} linked={student.telegram_linked} active={student.status === 'ACTIVE'} />],
                            ['O‘quv yili', student.academic_year],
                            ['Guruh', student.group],
                            ['Ro‘yxatdan o‘tgan', student.registered_at],
                            ['Joriy tashkilot', open ? `${open.organization ?? '—'} (${open.status === 'ACTIVE' ? 'faol' : 'kutilmoqda'})` : 'Biriktirilmagan'],
                        ]}
                    />
                    <StudentTelegramLink />
                </Card>

                <Card>
                    <CardHeader title="Amaliyot guruhlari" description="Talaba qatnashgan amaliyotlar va joriy rahbar." />
                    <Table head={['Guruh', 'O‘quv yili', 'Amaliyot muddati', 'Rahbar', 'Qo‘shilgan']}>
                        {student.participations.length === 0 ? (
                            <EmptyRow colSpan={5}>Amaliyot guruhiga qo‘shilmagan.</EmptyRow>
                        ) : (
                            student.participations.map((participation) => (
                                <tr key={participation.internship_id}>
                                    <Td className="font-medium text-heading">
                                        <Link href={`/internships/${participation.internship_id}`} className="hover:text-primary-600">
                                            {participation.group}
                                        </Link>
                                    </Td>
                                    <Td>{participation.academic_year}</Td>
                                    <Td className="whitespace-nowrap text-sm">
                                        {participation.period_start} — {participation.period_end}
                                    </Td>
                                    <Td>{participation.supervisor ?? '—'}</Td>
                                    <Td className="whitespace-nowrap text-sm">{participation.joined_at}</Td>
                                </tr>
                            ))
                        )}
                    </Table>
                    <div className="h-2" />
                </Card>

                <Card>
                    <CardHeader title="Biriktirishlar tarixi" description="Avvalgi joylar o‘chirilmaydi." />
                    <Table head={['Tashkilot', 'Guruh', 'Rahbar', 'Muddat', 'Holat']}>
                        {assignments.length === 0 ? (
                            <EmptyRow colSpan={5}>Biriktirish yo‘q.</EmptyRow>
                        ) : (
                            assignments.map((assignment) => (
                                <tr key={assignment.id}>
                                    <Td className="font-medium text-heading">
                                        <Link href={`/organizations/${assignment.organization_id}`} className="hover:text-primary-600">
                                            {assignment.organization}
                                        </Link>
                                    </Td>
                                    <Td>{assignment.group ?? '—'}</Td>
                                    <Td>{assignment.supervisor ?? '—'}</Td>
                                    <Td className="text-sm whitespace-nowrap">
                                        {assignment.start_at} — {assignment.end_at}
                                        {assignment.ended_at ? <span className="block text-xs text-muted">Yakunlandi: {assignment.ended_at}</span> : null}
                                        {assignment.cancel_reason ? <span className="block text-xs text-muted">Sabab: {assignment.cancel_reason}</span> : null}
                                    </Td>
                                    <Td>
                                        <StatusBadge status={assignment.status} />
                                    </Td>
                                </tr>
                            ))
                        )}
                    </Table>
                    <div className="h-2" />
                </Card>

                <Card>
                    <CardHeader title="Guruh a’zoligi tarixi" />
                    <Table head={['Guruh', 'O‘quv yili', 'Holat', 'Qo‘shilgan', 'Tugagan']}>
                        {student.memberships.length === 0 ? (
                            <EmptyRow colSpan={5}>A’zolik yozuvi yo‘q.</EmptyRow>
                        ) : (
                            student.memberships.map((membership) => (
                                <tr key={membership.id}>
                                    <Td className="font-medium text-heading">{membership.group}</Td>
                                    <Td>{membership.academic_year}</Td>
                                    <Td>
                                        <StatusBadge status={membership.status} />
                                    </Td>
                                    <Td className="whitespace-nowrap text-sm">{membership.joined_at}</Td>
                                    <Td className="whitespace-nowrap text-sm">{membership.ended_at ?? '—'}</Td>
                                </tr>
                            ))
                        )}
                    </Table>
                    <div className="h-2" />
                </Card>

                <Card>
                    <CardHeader title="Joy o‘zgartirish so‘rovlari" />
                    <ChangeRequestTable requests={changeRequests} role="ADMIN" showStudent={false} />
                    <div className="h-2" />
                </Card>

                <Card>
                    <CardHeader title="O‘zgarishlar tarixi" description="Tuzatishlar va holat o‘zgarishlari." />
                    <AuditTable logs={history} showEntity={false} />
                    <div className="h-2" />
                </Card>
            </div>

            <Modal title="Talaba ma’lumotini tuzatish" open={editing} onClose={() => setEditing(false)}>
                <form onSubmit={submitEdit} noValidate>
                    <ModalBody>
                        <p className="text-sm text-muted">Telegram ID o‘zgarmaydi: u talabaning shaxsini belgilaydi. Har bir tuzatish audit jurnaliga yoziladi.</p>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Familiya" htmlFor="last_name" error={edit.errors.last_name}>
                                <Input id="last_name" value={edit.data.last_name} onChange={(event) => edit.setData('last_name', event.target.value)} aria-invalid={Boolean(edit.errors.last_name)} />
                            </Field>
                            <Field label="Ism" htmlFor="first_name" error={edit.errors.first_name}>
                                <Input id="first_name" value={edit.data.first_name} onChange={(event) => edit.setData('first_name', event.target.value)} aria-invalid={Boolean(edit.errors.first_name)} />
                            </Field>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Telefon" htmlFor="phone" error={edit.errors.phone}>
                                <Input id="phone" value={edit.data.phone} onChange={(event) => edit.setData('phone', event.target.value)} aria-invalid={Boolean(edit.errors.phone)} />
                            </Field>
                            <Field label="Talaba ID" htmlFor="student_code" error={edit.errors.student_code}>
                                <Input id="student_code" value={edit.data.student_code} onChange={(event) => edit.setData('student_code', event.target.value)} aria-invalid={Boolean(edit.errors.student_code)} />
                            </Field>
                        </div>
                        <Field label="Sabab (ixtiyoriy)" htmlFor="edit_reason" error={edit.errors.reason}>
                            <Textarea id="edit_reason" value={edit.data.reason} onChange={(event) => edit.setData('reason', event.target.value)} />
                        </Field>
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="secondary" onClick={() => setEditing(false)}>
                            Bekor
                        </Button>
                        <Button type="submit" disabled={edit.processing}>
                            Saqlash
                        </Button>
                    </ModalFooter>
                </form>
            </Modal>

            <Modal title="Talaba holati" open={changingStatus} onClose={() => setChangingStatus(false)}>
                <form onSubmit={submitStatus} noValidate>
                    <ModalBody>
                        <p className="text-sm text-muted">Nofaol yoki bloklangan talaba Telegram orqali amaliyotga qo‘shila olmaydi. Tarix va biriktirishlar saqlanadi.</p>
                        <Field label="Holat" htmlFor="status" error={status.errors.status}>
                            <Select id="status" value={status.data.status} onChange={(event) => status.setData('status', event.target.value)}>
                                <option value="ACTIVE">Faol</option>
                                <option value="INACTIVE">Nofaol</option>
                                <option value="BLOCKED">Bloklangan</option>
                            </Select>
                        </Field>
                        <Field label="Sabab" htmlFor="status_reason" error={status.errors.reason}>
                            <Textarea id="status_reason" value={status.data.reason} onChange={(event) => status.setData('reason', event.target.value)} aria-invalid={Boolean(status.errors.reason)} />
                        </Field>
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="secondary" onClick={() => setChangingStatus(false)}>
                            Bekor
                        </Button>
                        <Button type="submit" disabled={status.processing || status.data.status === student.status}>
                            Saqlash
                        </Button>
                    </ModalFooter>
                </form>
            </Modal>
        </AppLayout>
    );
}

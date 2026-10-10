import { Link, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import ChangeRequestTable from '@/Components/ChangeRequestTable';
import Icon from '@/Components/Icon';
import Modal, { ModalBody, ModalFooter } from '@/Components/Modal';
import { StudentPhone, StudentTelegramLink, StudentTelegramStatus } from '@/Components/StudentTelegramRebind';
import { Button, Card, CardHeader, Dl, EmptyRow, Field, Input, Select, StatusBadge, Table, Td, Textarea } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';
import type { Assignment, ChangeRequest, OrganizationOption, StudentDetail } from '@/types';

type FormData = {
    request_type: 'EXISTING_ORGANIZATION' | 'NEW_ORGANIZATION';
    organization_id: number | '';
    organization: { name: string; address: string; contact_name: string; contact_phone: string };
    reason: string;
};

export default function StudentShow({
    student,
    assignments,
    changeRequests,
    organizations,
}: {
    student: StudentDetail;
    assignments: Assignment[];
    changeRequests: ChangeRequest[];
    organizations: OrganizationOption[];
}) {
    const [open, setOpen] = useState(false);
    const active = assignments.find((assignment) => assignment.status === 'ACTIVE') ?? null;
    const pending = assignments.find((assignment) => assignment.status === 'PENDING') ?? null;
    const current = active ?? pending;
    const hasPending = changeRequests.some((request) => request.status === 'PENDING');
    const choices = organizations.filter((organization) => organization.id !== active?.organization_id);
    const form = useForm<FormData>({
        request_type: 'EXISTING_ORGANIZATION',
        organization_id: choices[0]?.id ?? '',
        organization: { name: '', address: '', contact_name: '', contact_phone: '' },
        reason: '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((data) =>
            data.request_type === 'EXISTING_ORGANIZATION'
                ? { request_type: data.request_type, organization_id: data.organization_id, reason: data.reason }
                : { request_type: data.request_type, organization: data.organization, reason: data.reason },
        );
        form.post(`/students/${student.id}/change-requests`, {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    }

    const setOrganization = (key: keyof FormData['organization'], value: string) => form.setData('organization', { ...form.data.organization, [key]: value });

    return (
        <AppLayout title={student.name}>
            <div className="mb-4">
                <Link href="/my-students" className="inline-flex items-center gap-1 text-sm text-muted hover:text-primary-600">
                    <Icon name="chevronLeft" className="size-4" />
                    Talabalar
                </Link>
            </div>
            <div className="space-y-6">
                <Card>
                    <CardHeader
                        title={student.name}
                        description={[student.program, student.course, student.group].filter(Boolean).join(' · ') || undefined}
                        action={
                            <div className="flex flex-wrap gap-2">
                                <Link
                                    href={`/attendance/students/${student.id}`}
                                    className="inline-flex items-center gap-1.5 rounded-md bg-primary-500/15 px-5 py-2 text-[0.9375rem] font-medium text-primary-600 hover:bg-primary-500/25 dark:text-primary-300"
                                >
                                    <Icon name="clock" className="size-[1.125rem]" />
                                    Davomat
                                </Link>
                                <Button icon="swap" disabled={!active || hasPending} onClick={() => setOpen(true)} title={!active ? 'Faol biriktirish yo‘q' : hasPending ? 'Ko‘rib chiqilmagan so‘rov bor' : undefined}>
                                    Joy o‘zgartirish so‘rovi
                                </Button>
                            </div>
                        }
                    />
                    <Dl
                        items={[
                            ['Telefon', <StudentPhone key="ph" phone={student.phone} verified={student.phone_verified} />],
                            ['Talaba ID', student.student_code],
                            ['Holat', <StatusBadge key="s" status={student.status} />],
                            ['Joriy tashkilot', current?.organization ?? 'Biriktirilmagan'],
                            ['Biriktirish holati', current ? <StatusBadge key="a" status={current.status} /> : '—'],
                            ['Biriktirish muddati', current ? `${current.start_at} — ${current.end_at}` : '—'],
                            ['Fakultet', student.faculty],
                            ['O‘quv yili', student.academic_year],
                            ['Telegram', <StudentTelegramStatus key="tg" studentId={student.id} linked={student.telegram_linked} active={student.status === 'ACTIVE'} />],
                        ]}
                    />
                    <StudentTelegramLink />
                </Card>

                <Card>
                    <CardHeader title="Amaliyot guruhi" description="Sizga biriktirilgan amaliyot va uning muddati." />
                    <Table head={['Guruh', 'O‘quv yili', 'Amaliyot muddati', 'Rahbar']}>
                        {student.participations.length === 0 ? (
                            <EmptyRow colSpan={4}>Amaliyot yo‘q.</EmptyRow>
                        ) : (
                            student.participations.map((participation) => (
                                <tr key={participation.internship_id}>
                                    <Td className="font-medium text-heading">
                                        <Link href={`/my-groups/${participation.internship_id}`} className="hover:text-primary-600">
                                            {participation.group}
                                        </Link>
                                    </Td>
                                    <Td>{participation.academic_year}</Td>
                                    <Td className="whitespace-nowrap text-sm">
                                        {participation.period_start} — {participation.period_end}
                                    </Td>
                                    <Td>{participation.supervisor ?? '—'}</Td>
                                </tr>
                            ))
                        )}
                    </Table>
                    <div className="h-2" />
                </Card>

                <Card>
                    <CardHeader title="Biriktirishlar tarixi" description="Avvalgi joylar o‘chirilmaydi." />
                    <Table head={['Tashkilot', 'Muddat', 'Holat']}>
                        {assignments.length === 0 ? (
                            <EmptyRow colSpan={3}>Biriktirish yo‘q.</EmptyRow>
                        ) : (
                            assignments.map((assignment) => (
                                <tr key={assignment.id}>
                                    <Td className="font-medium text-heading">{assignment.organization}</Td>
                                    <Td className="text-sm whitespace-nowrap">
                                        {assignment.start_at} — {assignment.end_at}
                                        {assignment.ended_at ? <span className="block text-xs text-muted">Yakunlandi: {assignment.ended_at}</span> : null}
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
                    <CardHeader title="Joy o‘zgartirish so‘rovlari" />
                    <ChangeRequestTable requests={changeRequests} role="SUPERVISOR" showStudent={false} />
                    <div className="h-2" />
                </Card>
            </div>

            <Modal title="Joy o‘zgartirish so‘rovi" open={open} onClose={() => setOpen(false)}>
                <form onSubmit={submit} noValidate>
                    <ModalBody>
                        <Field label="So‘rov turi" htmlFor="request_type" error={errors.request_type}>
                            <Select id="request_type" value={form.data.request_type} onChange={(event) => form.setData('request_type', event.target.value as FormData['request_type'])}>
                                <option value="EXISTING_ORGANIZATION">Tizimdagi tashkilot</option>
                                <option value="NEW_ORGANIZATION">Tizimda yo‘q tashkilot</option>
                            </Select>
                        </Field>
                        {form.data.request_type === 'EXISTING_ORGANIZATION' ? (
                            <Field label="Yangi tashkilot" htmlFor="organization_id" error={errors.organization_id}>
                                <Select id="organization_id" value={form.data.organization_id} onChange={(event) => form.setData('organization_id', Number(event.target.value))}>
                                    {choices.map((organization) => (
                                        <option key={organization.id} value={organization.id}>
                                            {organization.name} — {organization.address}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                        ) : (
                            <>
                                <p className="text-sm text-muted">Joylashuv va radiusni administrator belgilaydi. Bu yerda faqat ma’lumot yuboriladi.</p>
                                <Field label="Tashkilot nomi" htmlFor="org_name" error={errors['organization.name'] ?? errors.organization}>
                                    <Input id="org_name" value={form.data.organization.name} onChange={(event) => setOrganization('name', event.target.value)} />
                                </Field>
                                <Field label="Manzil" htmlFor="org_address">
                                    <Input id="org_address" value={form.data.organization.address} onChange={(event) => setOrganization('address', event.target.value)} />
                                </Field>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field label="Mas’ul" htmlFor="org_contact">
                                        <Input id="org_contact" value={form.data.organization.contact_name} onChange={(event) => setOrganization('contact_name', event.target.value)} />
                                    </Field>
                                    <Field label="Telefon" htmlFor="org_phone">
                                        <Input id="org_phone" value={form.data.organization.contact_phone} onChange={(event) => setOrganization('contact_phone', event.target.value)} />
                                    </Field>
                                </div>
                            </>
                        )}
                        <Field label="Sabab" htmlFor="reason" error={errors.reason}>
                            <Textarea id="reason" value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />
                        </Field>
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="secondary" onClick={() => setOpen(false)}>
                            Bekor
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Yuborish
                        </Button>
                    </ModalFooter>
                </form>
            </Modal>
        </AppLayout>
    );
}

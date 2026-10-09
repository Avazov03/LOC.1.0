import { Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { WorkDaysPicker } from '@/Components/AttendanceUi';
import Icon from '@/Components/Icon';
import Modal, { ModalBody, ModalFooter } from '@/Components/Modal';
import ParticipantAssign from '@/Components/ParticipantAssign';
import { Button, Card, CardHeader, Dl, EmptyRow, Field, Input, Select, StatusBadge, Table, Td } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';
import type { Assignment, InternshipSummary, OrganizationOption, Participant, SharedProps } from '@/types';

type Period = { id: number; supervisor: string; supervisor_profile_id: number; starts_on: string; ends_on: string | null };
type Invite = { id: number; status: string; supervisor: string | null; created_at: string | null; expires_at: string | null; closed_at: string | null };
type SupervisorOption = { id: number; name: string; position: string };

type Props = {
    internship: InternshipSummary;
    periods: Period[];
    invites: Invite[];
    participants: Participant[];
    history: Assignment[];
    organizations: OrganizationOption[];
    supervisors: SupervisorOption[];
};

export default function InternshipShow({ internship, periods, invites, participants, history, organizations, supervisors }: Props) {
    const { flash } = usePage<SharedProps>().props;
    const current = periods.find((period) => period.ends_on === null) ?? null;
    const [replaceOpen, setReplaceOpen] = useState(false);
    const [inviteOpen, setInviteOpen] = useState(false);
    const [copied, setCopied] = useState(false);
    const replaceForm = useForm<{ supervisor_profile_id: number | '' }>({ supervisor_profile_id: supervisors.find((s) => s.id !== current?.supervisor_profile_id)?.id ?? '' });
    const inviteForm = useForm<{ expires_at: string }>({ expires_at: '' });
    const [datesOpen, setDatesOpen] = useState(false);
    const datesForm = useForm({ period_start: internship.period_start, period_end: internship.period_end });
    const [daysOpen, setDaysOpen] = useState(false);
    const daysForm = useForm<{ work_days: number[] }>({ work_days: internship.work_days });

    function updateDays(event: FormEvent) {
        event.preventDefault();
        daysForm.put(`/internships/${internship.id}/work-days`, { preserveScroll: true, onSuccess: () => setDaysOpen(false) });
    }

    function updateDates(event: FormEvent) {
        event.preventDefault();
        datesForm.put(`/internships/${internship.id}`, { preserveScroll: true, onSuccess: () => setDatesOpen(false) });
    }

    function replace(event: FormEvent) {
        event.preventDefault();
        replaceForm.post(`/internships/${internship.id}/supervisor`, { preserveScroll: true, onSuccess: () => setReplaceOpen(false) });
    }

    function createInvite(event: FormEvent) {
        event.preventDefault();
        inviteForm.post(`/internships/${internship.id}/invites`, { preserveScroll: true, onSuccess: () => setInviteOpen(false) });
    }

    function closeInvite(invite: Invite) {
        if (window.confirm('Havolani yopasizmi? Qo‘shilgan talabalar saqlanib qoladi.')) {
            router.post(`/invites/${invite.id}/close`, {}, { preserveScroll: true });
        }
    }

    async function copy(link: string) {
        await navigator.clipboard?.writeText(link);
        setCopied(true);
    }

    return (
        <AppLayout title={`Amaliyot: ${internship.group}`}>
            <div className="mb-4">
                <Link href="/internships" className="inline-flex items-center gap-1 text-sm text-muted hover:text-primary-600">
                    <Icon name="chevronLeft" className="size-4" />
                    Amaliyot guruhlari
                </Link>
            </div>

            <div className="space-y-6">
                <Card>
                    <CardHeader
                        title={`${internship.group} — ${internship.course}`}
                        description={internship.program}
                        action={
                            <div className="flex flex-wrap gap-2">
                                <Button variant="secondary" icon="calendar" onClick={() => setDatesOpen(true)}>
                                    Muddatni o‘zgartirish
                                </Button>
                                <Button variant="secondary" icon="clock" onClick={() => { daysForm.setData('work_days', internship.work_days); setDaysOpen(true); }}>
                                    Amaliyot kunlari
                                </Button>
                                <Button variant="tonal" icon="userCheck" onClick={() => setReplaceOpen(true)} disabled={supervisors.length === 0}>
                                    Rahbarni almashtirish
                                </Button>
                            </div>
                        }
                    />
                    <Dl
                        items={[
                            ['O‘quv yili', internship.year],
                            ['Muddat', `${internship.period_start} — ${internship.period_end}`],
                            ['Amaliyot kunlari', internship.work_days_label],
                            ['Joriy rahbar', current?.supervisor ?? '—'],
                            ['Talabalar', participants.length],
                        ]}
                    />
                </Card>

                {flash.invite_link ? (
                    <div className="rounded-lg border border-primary-500/40 bg-primary-500/10 p-4" role="status">
                        <p className="text-sm font-medium text-heading">Yangi taklif havolasi. U faqat hozir ko‘rsatiladi, bazada saqlanmaydi:</p>
                        <div className="mt-2 flex flex-wrap items-center gap-2">
                            <code className="min-w-0 flex-1 rounded bg-panel px-3 py-2 text-sm break-all text-heading">{flash.invite_link}</code>
                            <Button size="sm" icon={copied ? 'check' : 'copy'} onClick={() => copy(flash.invite_link!)}>
                                {copied ? 'Nusxalandi' : 'Nusxalash'}
                            </Button>
                        </div>
                    </div>
                ) : null}

                <Card>
                    <CardHeader
                        title="Taklif havolalari"
                        description="Talaba havola orqali Telegram botda ro‘yxatdan o‘tadi. Yopilgan yoki muddati tugagan havola yangi talabani qabul qilmaydi."
                        action={
                            <Button icon="plus" onClick={() => setInviteOpen(true)} disabled={!current}>
                                Havola yaratish
                            </Button>
                        }
                    />
                    <Table head={['#', 'Rahbar', 'Yaratilgan', 'Amal qiladi', 'Holat', '']}>
                        {invites.length === 0 ? (
                            <EmptyRow colSpan={6}>Havola yo‘q.</EmptyRow>
                        ) : (
                            invites.map((invite) => (
                                <tr key={invite.id}>
                                    <Td>{invite.id}</Td>
                                    <Td>{invite.supervisor ?? '—'}</Td>
                                    <Td>{invite.created_at}</Td>
                                    <Td>{invite.expires_at ?? 'Muddatsiz'}</Td>
                                    <Td>
                                        <StatusBadge status={invite.status} />
                                        {invite.closed_at ? <span className="block text-xs text-muted">{invite.closed_at}</span> : null}
                                    </Td>
                                    <Td className="text-right">
                                        {invite.status === 'ACTIVE' ? (
                                            <Button size="sm" variant="secondary" icon="ban" onClick={() => closeInvite(invite)}>
                                                Yopish
                                            </Button>
                                        ) : null}
                                    </Td>
                                </tr>
                            ))
                        )}
                    </Table>
                    <div className="h-2" />
                </Card>

                <ParticipantAssign
                    internshipId={internship.id}
                    groupWorkDays={internship.work_days}
                    groupWorkDaysLabel={internship.work_days_label}
                    periodStart={internship.period_start}
                    periodEnd={internship.period_end}
                    participants={participants}
                    organizations={organizations}
                />

                <div className="grid gap-6 xl:grid-cols-3">
                    <Card className="xl:col-span-2">
                        <CardHeader title="Biriktirishlar tarixi" description="Oxirgi 50 ta. Yakunlangan va bekor qilinganlar o‘chirilmaydi." />
                        <Table head={['Talaba', 'Tashkilot', 'Muddat', 'Holat']}>
                            {history.length === 0 ? (
                                <EmptyRow colSpan={4}>Biriktirish yo‘q.</EmptyRow>
                            ) : (
                                history.map((row) => (
                                    <tr key={row.id}>
                                        <Td className="font-medium text-heading">{row.student}</Td>
                                        <Td>{row.organization}</Td>
                                        <Td className="whitespace-nowrap text-sm">
                                            {row.start_at} — {row.end_at}
                                        </Td>
                                        <Td>
                                            <StatusBadge status={row.status} />
                                        </Td>
                                    </tr>
                                ))
                            )}
                        </Table>
                        <div className="h-2" />
                    </Card>
                    <Card>
                        <CardHeader title="Rahbarlik tarixi" />
                        <ul className="divide-y divide-line px-6 pb-4">
                            {periods.map((period) => (
                                <li key={period.id} className="py-3">
                                    <p className="font-medium text-heading">{period.supervisor}</p>
                                    <p className="text-sm text-muted">
                                        {period.starts_on} — {period.ends_on ?? 'hozirgacha'}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </Card>
                </div>
            </div>

            <Modal title="Amaliyot muddati" open={datesOpen} onClose={() => setDatesOpen(false)}>
                <form onSubmit={updateDates} noValidate>
                    <ModalBody>
                        <p className="text-sm text-muted">Guruh va o‘quv yili o‘zgarmaydi. Mavjud biriktirishlar muddati avtomatik surilmaydi; kerak bo‘lsa, ularni Biriktirishlar sahifasida alohida tuzating.</p>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Boshlanish" htmlFor="period_start" error={datesForm.errors.period_start}>
                                <Input id="period_start" type="date" value={datesForm.data.period_start} onChange={(event) => datesForm.setData('period_start', event.target.value)} />
                            </Field>
                            <Field label="Tugash" htmlFor="period_end" error={datesForm.errors.period_end}>
                                <Input id="period_end" type="date" value={datesForm.data.period_end} onChange={(event) => datesForm.setData('period_end', event.target.value)} />
                            </Field>
                        </div>
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="secondary" onClick={() => setDatesOpen(false)}>
                            Bekor
                        </Button>
                        <Button type="submit" disabled={datesForm.processing}>
                            Saqlash
                        </Button>
                    </ModalFooter>
                </form>
            </Modal>

            <Modal title="Amaliyot kunlari" open={daysOpen} onClose={() => setDaysOpen(false)}>
                <form onSubmit={updateDays} noValidate>
                    <ModalBody>
                        <p className="text-sm text-muted">
                            Butun guruh uchun. Boshqa kunlarda bot kelishni qabul qilmaydi va ular «Kelmadi» hisoblanmaydi. O‘zgarish o‘tgan kunlar hisobiga ham ta’sir qiladi.
                            Alohida kunlari belgilangan talabalarga ta’sir qilmaydi.
                        </p>
                        <WorkDaysPicker
                            value={daysForm.data.work_days}
                            onChange={(days) => daysForm.setData('work_days', days)}
                            error={daysForm.errors.work_days ?? Object.entries(daysForm.errors).find(([key]) => key.startsWith('work_days.'))?.[1]}
                        />
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="secondary" onClick={() => setDaysOpen(false)}>
                            Bekor
                        </Button>
                        <Button type="submit" disabled={daysForm.processing || daysForm.data.work_days.length === 0}>
                            Saqlash
                        </Button>
                    </ModalFooter>
                </form>
            </Modal>

            <Modal title="Rahbarni almashtirish" open={replaceOpen} onClose={() => setReplaceOpen(false)}>
                <form onSubmit={replace} noValidate>
                    <ModalBody>
                        <p className="text-sm text-muted">Bugundan boshlab yangi rahbar guruhni boshqaradi. Avvalgi rahbar kirish huquqini yo‘qotadi, tarix saqlanadi.</p>
                        <Field label="Yangi rahbar" htmlFor="supervisor_profile_id" error={replaceForm.errors.supervisor_profile_id}>
                            <Select
                                id="supervisor_profile_id"
                                value={replaceForm.data.supervisor_profile_id}
                                onChange={(event) => replaceForm.setData('supervisor_profile_id', Number(event.target.value))}
                            >
                                {supervisors
                                    .filter((supervisor) => supervisor.id !== current?.supervisor_profile_id)
                                    .map((supervisor) => (
                                        <option key={supervisor.id} value={supervisor.id}>
                                            {supervisor.name} — {supervisor.position}
                                        </option>
                                    ))}
                            </Select>
                        </Field>
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="secondary" onClick={() => setReplaceOpen(false)}>
                            Bekor
                        </Button>
                        <Button type="submit" disabled={replaceForm.processing || replaceForm.data.supervisor_profile_id === ''}>
                            Almashtirish
                        </Button>
                    </ModalFooter>
                </form>
            </Modal>

            <Modal title="Taklif havolasi yaratish" open={inviteOpen} onClose={() => setInviteOpen(false)}>
                <form onSubmit={createInvite} noValidate>
                    <ModalBody>
                        <p className="text-sm text-muted">Havola joriy rahbar ({current?.supervisor ?? '—'}) bilan bog‘lanadi. Havola faqat bir marta ko‘rsatiladi.</p>
                        <Field label="Amal qilish muddati (ixtiyoriy)" htmlFor="expires_at" error={inviteForm.errors.expires_at}>
                            <Input id="expires_at" type="datetime-local" value={inviteForm.data.expires_at} onChange={(event) => inviteForm.setData('expires_at', event.target.value)} />
                        </Field>
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="secondary" onClick={() => setInviteOpen(false)}>
                            Bekor
                        </Button>
                        <Button type="submit" disabled={inviteForm.processing}>
                            Yaratish
                        </Button>
                    </ModalFooter>
                </form>
            </Modal>
        </AppLayout>
    );
}

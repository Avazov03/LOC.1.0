import { Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { DayStatusBadge, duration } from '@/Components/AttendanceUi';
import Modal, { ModalBody, ModalFooter } from '@/Components/Modal';
import { Badge, Button, Card, CardHeader, Dl, EmptyRow, Field, Input, StatusBadge, Table, Td, Textarea } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';
import type { DayStatus } from '@/types';

type Day = { date: string; status: DayStatus | null; first_check_in: string | null; last_check_out: string | null; completed_seconds: number; failed_count: number };
type Session = { id: number; date: string; status: 'OPEN' | 'COMPLETED' | 'INCOMPLETE'; opened_at: string; closed_at: string | null; duration_seconds: number | null; organization: string | null };
type Event = {
    id: number;
    date: string;
    occurred_at: string;
    recorded_at: string | null;
    type: string;
    type_label: string;
    verification: string;
    verification_label: string;
    verified: boolean;
    distance: number | null;
    accuracy: number | null;
    radius: number | null;
    latitude: number | null;
    longitude: number | null;
    source: string;
    organization: string | null;
    actor: string | null;
    reason: string | null;
    flags: string[];
};
type Policy = {
    source: string;
    check_in_enabled: boolean;
    check_out_enabled: boolean;
    minimum_duration_minutes: number | null;
    multiple_sessions_allowed: boolean;
    location_required: boolean;
    accuracy_threshold_meters: number | null;
    manual_correction_allowed: boolean;
};

const sessionTone = { OPEN: 'warning', COMPLETED: 'success', INCOMPLETE: 'danger' } as const;
const sessionLabel = { OPEN: 'Ochiq', COMPLETED: 'Yakunlangan', INCOMPLETE: 'Yakunlanmagan' } as const;
const sourceLabel: Record<string, string> = { TELEGRAM: 'Telegram', MANUAL: 'Admin', SYSTEM: 'Tizim' };

export default function AttendanceStudent({
    student,
    from,
    to,
    today,
    days,
    sessions,
    events,
    policy,
    canCorrect,
    backUrl,
}: {
    student: { id: number; name: string; group: string | null; student_code: string | null; phone: string; status: string };
    from: string;
    to: string;
    today: string;
    days: Day[];
    sessions: Session[];
    events: Event[];
    policy: Policy;
    canCorrect: boolean;
    backUrl: string;
}) {
    const [adding, setAdding] = useState(false);
    const [closing, setClosing] = useState<Session | null>(null);
    const add = useForm({ date: to, check_in: '09:00', check_out: '', reason: '' });
    const close = useForm({ check_out: '', reason: '' });

    function range(next: { from?: string; to?: string }) {
        router.get(`/attendance/students/${student.id}`, { from, to, ...next }, { preserveScroll: true, replace: true });
    }

    function submitAdd(event: FormEvent) {
        event.preventDefault();
        add.post(`/attendance/students/${student.id}/corrections`, { preserveScroll: true, onSuccess: () => { setAdding(false); add.reset(); } });
    }

    function submitClose(event: FormEvent) {
        event.preventDefault();
        if (!closing) {
            return;
        }
        close.post(`/attendance/sessions/${closing.id}/close`, { preserveScroll: true, onSuccess: () => { setClosing(null); close.reset(); } });
    }

    return (
        <AppLayout title={`Davomat · ${student.name}`}>
            <div className="mb-4 flex flex-wrap items-center gap-3 text-sm">
                <Link href="/attendance" className="text-primary-600 hover:underline dark:text-primary-300">← Davomat</Link>
                <span className="text-muted">·</span>
                <Link href={backUrl} className="text-primary-600 hover:underline dark:text-primary-300">Talaba profili</Link>
            </div>

            <div className="space-y-6">
                <Card>
                    <CardHeader
                        title={student.name}
                        description="Har bir urinish o‘zgarmas yozuv sifatida saqlanadi. Tuzatishlar yangi yozuv qo‘shadi, eskisini o‘zgartirmaydi."
                        action={canCorrect ? <Button icon="plus" onClick={() => setAdding(true)}>Davomat qo‘shish</Button> : undefined}
                    />
                    <Dl
                        items={[
                            ['Guruh', student.group],
                            ['Talaba ID', student.student_code],
                            ['Telefon', student.phone],
                            ['Holat', <StatusBadge key="s" status={student.status} />],
                        ]}
                    />
                    <div className="border-t border-line px-6 py-4 text-sm text-muted">
                        Siyosat ({policy.source === 'GROUP' ? 'guruh' : policy.source === 'UNIVERSITY' ? 'universitet' : 'standart'}): minimal davomiylik{' '}
                        {policy.minimum_duration_minutes ? `${policy.minimum_duration_minutes} daqiqa` : 'o‘chiq'} · bir kunda bir nechta sessiya{' '}
                        {policy.multiple_sessions_allowed ? 'ruxsat' : 'yo‘q'} · joylashuv {policy.location_required ? 'majburiy' : 'talab qilinmaydi'} · aniqlik chegarasi{' '}
                        {policy.accuracy_threshold_meters ? `${policy.accuracy_threshold_meters} m` : 'o‘chiq'}
                    </div>
                </Card>

                <Card>
                    <CardHeader title="Kunlar" description={`${from} — ${to}`} />
                    <div className="flex flex-wrap gap-3 px-6 pb-4">
                        <Input type="date" className="sm:max-w-[11rem]" value={from} max={today} onChange={(event) => range({ from: event.target.value })} aria-label="Boshlanish sanasi" />
                        <Input type="date" className="sm:max-w-[11rem]" value={to} max={today} onChange={(event) => range({ to: event.target.value })} aria-label="Tugash sanasi" />
                    </div>
                    <Table head={['Sana', 'Holat', 'Kelish', 'Ketish', 'Davomiylik', 'Rad etilgan']}>
                        {days.filter((day) => day.status).length === 0 ? (
                            <EmptyRow colSpan={6}>Bu oraliqda davomat yozuvi yo‘q.</EmptyRow>
                        ) : (
                            days
                                .filter((day) => day.status)
                                .map((day) => (
                                    <tr key={day.date}>
                                        <Td className="tabular-nums whitespace-nowrap">{day.date}</Td>
                                        <Td><DayStatusBadge status={day.status} /></Td>
                                        <Td className="tabular-nums">{day.first_check_in ?? '—'}</Td>
                                        <Td className="tabular-nums">{day.last_check_out ?? '—'}</Td>
                                        <Td className="tabular-nums whitespace-nowrap">{duration(day.completed_seconds)}</Td>
                                        <Td className="tabular-nums">{day.failed_count || '—'}</Td>
                                    </tr>
                                ))
                        )}
                    </Table>
                    <div className="h-2" />
                </Card>

                <Card>
                    <CardHeader title="Sessiyalar" />
                    <Table head={['Sana', 'Tashkilot', 'Kelish', 'Ketish', 'Davomiylik', 'Holat', '']}>
                        {sessions.length === 0 ? (
                            <EmptyRow colSpan={7}>Sessiya yo‘q.</EmptyRow>
                        ) : (
                            sessions.map((session) => (
                                <tr key={session.id}>
                                    <Td className="tabular-nums whitespace-nowrap">{session.date}</Td>
                                    <Td>{session.organization ?? '—'}</Td>
                                    <Td className="tabular-nums">{session.opened_at}</Td>
                                    <Td className="tabular-nums">{session.closed_at ?? '—'}</Td>
                                    <Td className="tabular-nums whitespace-nowrap">{session.duration_seconds !== null ? duration(session.duration_seconds) : '—'}</Td>
                                    <Td><Badge tone={sessionTone[session.status]}>{sessionLabel[session.status]}</Badge></Td>
                                    <Td className="text-right">
                                        {canCorrect && session.status !== 'COMPLETED' ? (
                                            <Button size="sm" variant="tonal" onClick={() => setClosing(session)}>Yopish</Button>
                                        ) : null}
                                    </Td>
                                </tr>
                            ))
                        )}
                    </Table>
                    <div className="h-2" />
                </Card>

                <Card>
                    <CardHeader title="Dalillar va urinishlar" description="Masofa PostGIS bilan hisoblangan; radius va tashkilot nuqtasi urinish paytidagi nusxadan olinadi." />
                    <Table head={['Vaqt', 'Turi', 'Natija', 'Masofa / radius', 'Aniqlik', 'Joylashuv', 'Manba']}>
                        {events.length === 0 ? (
                            <EmptyRow colSpan={7}>Yozuv yo‘q.</EmptyRow>
                        ) : (
                            events.map((event) => (
                                <tr key={event.id}>
                                    <Td className="tabular-nums whitespace-nowrap">
                                        {event.occurred_at}
                                        {event.source === 'MANUAL' && event.recorded_at ? <span className="block text-xs text-muted">yozilgan: {event.recorded_at}</span> : null}
                                    </Td>
                                    <Td>
                                        {event.type_label}
                                        {event.reason && event.source !== 'TELEGRAM' ? <span className="block max-w-xs text-xs text-muted">{event.reason}</span> : null}
                                        {event.flags.map((flag) => (
                                            <span key={flag} className="block text-xs text-warning">{flag}</span>
                                        ))}
                                    </Td>
                                    <Td><Badge tone={event.verified ? 'success' : event.verification === 'NOT_APPLICABLE' ? 'secondary' : 'danger'}>{event.verification_label}</Badge></Td>
                                    <Td className="tabular-nums whitespace-nowrap">
                                        {event.distance !== null ? `${Math.round(event.distance)} m` : '—'}
                                        {event.radius !== null ? <span className="text-muted"> / {event.radius} m</span> : null}
                                    </Td>
                                    <Td className="tabular-nums">{event.accuracy !== null ? `±${Math.round(event.accuracy)} m` : '—'}</Td>
                                    <Td className="whitespace-nowrap">
                                        {event.latitude !== null && event.longitude !== null ? (
                                            <a
                                                href={`https://www.openstreetmap.org/?mlat=${event.latitude}&mlon=${event.longitude}#map=17/${event.latitude}/${event.longitude}`}
                                                target="_blank"
                                                rel="noreferrer noopener"
                                                className="text-primary-600 hover:underline dark:text-primary-300"
                                            >
                                                Xaritada
                                            </a>
                                        ) : (
                                            '—'
                                        )}
                                    </Td>
                                    <Td>
                                        {sourceLabel[event.source] ?? event.source}
                                        {event.actor ? <span className="block text-xs text-muted">{event.actor}</span> : null}
                                    </Td>
                                </tr>
                            ))
                        )}
                    </Table>
                    <div className="h-2" />
                </Card>
            </div>

            <Modal title="Davomat qo‘shish (qo‘lda tuzatish)" open={adding} onClose={() => setAdding(false)}>
                <form onSubmit={submitAdd} noValidate>
                    <ModalBody>
                        <Field label="Sana" htmlFor="c-date" error={add.errors.date}>
                            <Input id="c-date" type="date" max={today} value={add.data.date} onChange={(event) => add.setData('date', event.target.value)} />
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Kelish vaqti" htmlFor="c-in" error={add.errors.check_in}>
                                <Input id="c-in" type="time" value={add.data.check_in} onChange={(event) => add.setData('check_in', event.target.value)} />
                            </Field>
                            <Field label="Ketish vaqti (ixtiyoriy)" htmlFor="c-out" error={add.errors.check_out}>
                                <Input id="c-out" type="time" value={add.data.check_out} onChange={(event) => add.setData('check_out', event.target.value)} />
                            </Field>
                        </div>
                        <Field label="Sabab" htmlFor="c-reason" error={add.errors.reason}>
                            <Textarea id="c-reason" value={add.data.reason} onChange={(event) => add.setData('reason', event.target.value)} placeholder="Masalan: tashkilot rahbari yozma tasdiqladi" />
                        </Field>
                        <p className="text-sm text-muted">Asl yozuvlar o‘zgarmaydi. Tuzatish alohida yozuv va audit jurnalida saqlanadi.</p>
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="secondary" onClick={() => setAdding(false)}>Bekor qilish</Button>
                        <Button type="submit" disabled={add.processing}>Saqlash</Button>
                    </ModalFooter>
                </form>
            </Modal>

            <Modal title={`Sessiyani yopish · ${closing?.date ?? ''}`} open={closing !== null} onClose={() => setClosing(null)}>
                <form onSubmit={submitClose} noValidate>
                    <ModalBody>
                        <p className="text-sm text-muted">Kelish: {closing?.opened_at}. Ketish vaqtini shu sana bo‘yicha kiriting.</p>
                        <Field label="Ketish vaqti" htmlFor="s-out" error={close.errors.check_out}>
                            <Input id="s-out" type="time" value={close.data.check_out} onChange={(event) => close.setData('check_out', event.target.value)} />
                        </Field>
                        <Field label="Sabab" htmlFor="s-reason" error={close.errors.reason}>
                            <Textarea id="s-reason" value={close.data.reason} onChange={(event) => close.setData('reason', event.target.value)} />
                        </Field>
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="secondary" onClick={() => setClosing(null)}>Bekor qilish</Button>
                        <Button type="submit" disabled={close.processing}>Yopish</Button>
                    </ModalFooter>
                </form>
            </Modal>
        </AppLayout>
    );
}

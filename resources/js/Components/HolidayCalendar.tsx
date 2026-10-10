import { router, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { Badge, Button, Card, CardHeader, EmptyRow, Field, Input, Table, Td } from '@/Components/ui';

export type Holiday = { id: number; date: string; name: string };

const WEEKDAYS = ['Yakshanba', 'Dushanba', 'Seshanba', 'Chorshanba', 'Payshanba', 'Juma', 'Shanba'];

function weekday(date: string): string {
    return WEEKDAYS[new Date(`${date}T12:00:00`).getDay()];
}

export default function HolidayCalendar({ holidays, today }: { holidays: Holiday[]; today: string }) {
    const form = useForm({ date: '', name: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/settings/holidays', { preserveScroll: true, onSuccess: () => form.reset() });
    }

    function remove(holiday: Holiday) {
        if (window.confirm(`${holiday.date} (${holiday.name}) dam olish kunlari ro‘yxatidan o‘chirilsinmi? Shu kun yana oddiy ish kuni bo‘ladi.`)) {
            router.delete(`/settings/holidays/${holiday.id}`, { preserveScroll: true });
        }
    }

    return (
        <Card>
            <CardHeader
                title="Dam olish kunlari"
                description="Bayram va universitet dam olish kunlari. Bu kunlarda bot kelishni qabul qilmaydi, hech kim «Kelmadi» deb hisoblanmaydi va kechki ro‘yxat yuborilmaydi."
            />
            <form onSubmit={submit} noValidate className="grid gap-4 px-6 pb-4 sm:grid-cols-[11rem_1fr_auto] sm:items-end">
                <Field label="Sana" htmlFor="holiday-date" error={form.errors.date}>
                    <Input id="holiday-date" type="date" value={form.data.date} onChange={(event) => form.setData('date', event.target.value)} aria-invalid={Boolean(form.errors.date)} />
                </Field>
                <Field label="Nomi" htmlFor="holiday-name" error={form.errors.name}>
                    <Input
                        id="holiday-name"
                        placeholder="Masalan: Mustaqillik kuni"
                        maxLength={120}
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        aria-invalid={Boolean(form.errors.name)}
                    />
                </Field>
                <Button type="submit" icon="plus" disabled={form.processing || form.data.date === '' || form.data.name.trim() === ''}>
                    Qo‘shish
                </Button>
            </form>
            <Table head={['Sana', 'Kun', 'Nomi', '']}>
                {holidays.length === 0 ? (
                    <EmptyRow colSpan={4}>Dam olish kunlari qo‘shilmagan.</EmptyRow>
                ) : (
                    holidays.map((holiday) => (
                        <tr key={holiday.id} className={holiday.date < today ? 'opacity-60' : undefined}>
                            <Td className="whitespace-nowrap tabular-nums">{holiday.date}</Td>
                            <Td>{weekday(holiday.date)}</Td>
                            <Td className="font-medium text-heading">
                                {holiday.name}
                                {holiday.date === today ? (
                                    <span className="ml-2">
                                        <Badge tone="info">bugun</Badge>
                                    </span>
                                ) : null}
                            </Td>
                            <Td className="text-right">
                                <Button variant="ghost" onClick={() => remove(holiday)}>
                                    O‘chirish
                                </Button>
                            </Td>
                        </tr>
                    ))
                )}
            </Table>
            <div className="h-2" />
        </Card>
    );
}

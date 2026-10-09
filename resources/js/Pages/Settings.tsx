import { useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import AuditTable from '@/Components/AuditTable';
import { Button, Card, CardHeader, Field, Input, Select } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';
import type { AuditEntry } from '@/types';

export default function Settings({
    university,
    timezones,
    history,
}: {
    university: { name: string; slug: string; timezone: string; reminder_time: string };
    timezones: string[];
    history: AuditEntry[];
}) {
    const form = useForm({ name: university.name, timezone: university.timezone, reminder_time: university.reminder_time });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put('/settings', { preserveScroll: true });
    }

    return (
        <AppLayout title="Sozlamalar">
            <div className="space-y-6">
                <Card>
                    <CardHeader
                        title="Universitet sozlamalari"
                        description="Vaqt zonasi sanalarni ko‘rsatish va kiritilgan sanalarni o‘qish uchun ishlatiladi. Saqlangan vaqtlar UTC’da qoladi va qayta yozilmaydi."
                    />
                    <form onSubmit={submit} noValidate className="grid gap-5 px-6 pb-6 md:max-w-2xl">
                        <Field label="Universitet nomi" htmlFor="name" error={form.errors.name}>
                            <Input id="name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} aria-invalid={Boolean(form.errors.name)} />
                        </Field>
                        <Field label="Vaqt zonasi" htmlFor="timezone" error={form.errors.timezone}>
                            <Select id="timezone" value={form.data.timezone} onChange={(event) => form.setData('timezone', event.target.value)} aria-invalid={Boolean(form.errors.timezone)}>
                                {timezones.map((timezone) => (
                                    <option key={timezone} value={timezone}>
                                        {timezone}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Kunlik eslatma vaqti" htmlFor="reminder_time" error={form.errors.reminder_time}>
                            <Input
                                id="reminder_time"
                                type="time"
                                className="sm:max-w-[9rem]"
                                value={form.data.reminder_time}
                                onChange={(event) => form.setData('reminder_time', event.target.value)}
                                aria-invalid={Boolean(form.errors.reminder_time)}
                            />
                        </Field>
                        <p className="-mt-3 text-sm text-muted">
                            Shu vaqtda ketishni qayd etmagan talabaga eslatma, rahbarlarga esa bugun kelmaganlar ro‘yxati Telegram orqali yuboriladi (universitet vaqt zonasi bo‘yicha).
                        </p>
                        <p className="text-sm text-muted">Qisqa nom (slug): {university.slug}</p>
                        <div>
                            <Button type="submit" disabled={form.processing || !form.isDirty}>
                                Saqlash
                            </Button>
                        </div>
                    </form>
                </Card>

                <Card>
                    <CardHeader title="O‘zgarishlar tarixi" />
                    <AuditTable logs={history} showEntity={false} />
                    <div className="h-2" />
                </Card>
            </div>
        </AppLayout>
    );
}

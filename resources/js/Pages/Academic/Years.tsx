import { useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import Modal, { ModalBody, ModalFooter } from '@/Components/Modal';
import { Button, Card, CardHeader, EmptyRow, Field, Input, Select, StatusBadge, Table, Td } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';

type Year = { id: number; name: string; starts_on: string; ends_on: string; status: string };

const dateFormat = new Intl.DateTimeFormat('uz-UZ', { day: '2-digit', month: '2-digit', year: 'numeric' });

function formatDate(value: string): string {
    return dateFormat.format(new Date(`${value}T00:00:00`));
}

export default function Years({ years }: { years: Year[] }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Year | null>(null);
    const form = useForm({ name: '', starts_on: '', ends_on: '', status: 'ACTIVE' });

    function openForm(year: Year | null) {
        setEditing(year);
        form.setData({
            name: year?.name ?? '',
            starts_on: year?.starts_on ?? '',
            ends_on: year?.ends_on ?? '',
            status: year?.status ?? 'ACTIVE',
        });
        form.clearErrors();
        setOpen(true);
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) {
            form.put(`/academic/years/${editing.id}`, options);
            return;
        }
        form.post('/academic/years', options);
    }

    return (
        <AppLayout title="O‘quv yillari">
            <Card>
                <CardHeader
                    title="O‘quv yillari"
                    description="Masalan 2026/2027. Eski yil yozuvlari o‘chirilmaydi, yopiladi."
                    action={
                        <Button icon="plus" onClick={() => openForm(null)}>
                            Yangi o‘quv yili
                        </Button>
                    }
                />
                <Table head={['Nomi', 'Boshlanish', 'Tugash', 'Holat', '']}>
                    {years.length === 0 ? (
                        <EmptyRow colSpan={5}>O‘quv yili yo‘q.</EmptyRow>
                    ) : (
                        years.map((year) => (
                            <tr key={year.id}>
                                <Td className="font-medium text-heading">{year.name}</Td>
                                <Td className="tabular-nums">{formatDate(year.starts_on)}</Td>
                                <Td className="tabular-nums">{formatDate(year.ends_on)}</Td>
                                <Td>
                                    <StatusBadge status={year.status} />
                                </Td>
                                <Td className="text-right">
                                    <Button size="sm" variant="tonal" icon="pencil" onClick={() => openForm(year)}>
                                        Tahrirlash
                                    </Button>
                                </Td>
                            </tr>
                        ))
                    )}
                </Table>
                <div className="h-2" />
            </Card>

            <Modal title={editing ? 'O‘quv yilini tahrirlash' : 'Yangi o‘quv yili'} open={open} onClose={() => setOpen(false)}>
                <form onSubmit={submit} noValidate>
                    <ModalBody>
                        <Field label="Nomi" htmlFor="name" error={form.errors.name}>
                            <Input id="name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} placeholder="2026/2027" aria-invalid={form.errors.name ? true : undefined} />
                        </Field>
                        <div className="grid gap-5 sm:grid-cols-2">
                            <Field label="Boshlanish" htmlFor="starts_on" error={form.errors.starts_on}>
                                <Input id="starts_on" type="date" value={form.data.starts_on} onChange={(event) => form.setData('starts_on', event.target.value)} />
                            </Field>
                            <Field label="Tugash" htmlFor="ends_on" error={form.errors.ends_on}>
                                <Input id="ends_on" type="date" value={form.data.ends_on} onChange={(event) => form.setData('ends_on', event.target.value)} aria-invalid={form.errors.ends_on ? true : undefined} />
                            </Field>
                        </div>
                        {editing ? (
                            <Field label="Holat" htmlFor="status" error={form.errors.status}>
                                <Select id="status" value={form.data.status} onChange={(event) => form.setData('status', event.target.value)}>
                                    <option value="ACTIVE">Faol</option>
                                    <option value="CLOSED">Yopilgan</option>
                                </Select>
                            </Field>
                        ) : null}
                    </ModalBody>
                    <ModalFooter>
                        <Button variant="secondary" onClick={() => setOpen(false)}>
                            Bekor
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Saqlash
                        </Button>
                    </ModalFooter>
                </form>
            </Modal>
        </AppLayout>
    );
}

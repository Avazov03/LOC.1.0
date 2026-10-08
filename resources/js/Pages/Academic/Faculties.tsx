import { useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import Modal, { ModalBody, ModalFooter } from '@/Components/Modal';
import { Button, Card, CardHeader, EmptyRow, Field, Input, Select, StatusBadge, Table, Td } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';

type Faculty = { id: number; name: string; status: string; programs_count: number };

export default function Faculties({ faculties }: { faculties: Faculty[] }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Faculty | null>(null);
    const form = useForm({ name: '', status: 'ACTIVE' });

    function openForm(faculty: Faculty | null) {
        setEditing(faculty);
        form.setData({ name: faculty?.name ?? '', status: faculty?.status ?? 'ACTIVE' });
        form.clearErrors();
        setOpen(true);
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) {
            form.put(`/academic/faculties/${editing.id}`, options);
            return;
        }
        form.post('/academic/faculties', options);
    }

    return (
        <AppLayout title="Fakultetlar">
            <Card>
                <CardHeader
                    title="Fakultetlar"
                    description="Universitet tarkibidagi fakultetlar. O‘chirilmaydi, faqat nofaol qilinadi."
                    action={
                        <Button icon="plus" onClick={() => openForm(null)}>
                            Yangi fakultet
                        </Button>
                    }
                />
                <Table head={['Nomi', 'Yo‘nalishlar', 'Holat', '']}>
                    {faculties.length === 0 ? (
                        <EmptyRow colSpan={4}>Fakultet yo‘q. “Yangi fakultet” tugmasi bilan qo‘shing.</EmptyRow>
                    ) : (
                        faculties.map((faculty) => (
                            <tr key={faculty.id}>
                                <Td className="font-medium text-heading">{faculty.name}</Td>
                                <Td className="tabular-nums">{faculty.programs_count}</Td>
                                <Td>
                                    <StatusBadge status={faculty.status} />
                                </Td>
                                <Td className="text-right">
                                    <Button size="sm" variant="tonal" icon="pencil" onClick={() => openForm(faculty)}>
                                        Tahrirlash
                                    </Button>
                                </Td>
                            </tr>
                        ))
                    )}
                </Table>
                <div className="h-2" />
            </Card>

            <Modal title={editing ? 'Fakultetni tahrirlash' : 'Yangi fakultet'} open={open} onClose={() => setOpen(false)}>
                <form onSubmit={submit} noValidate>
                    <ModalBody>
                        <Field label="Nomi" htmlFor="name" error={form.errors.name}>
                            <Input id="name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} autoFocus aria-invalid={form.errors.name ? true : undefined} />
                        </Field>
                        {editing ? (
                            <Field label="Holat" htmlFor="status" error={form.errors.status}>
                                <Select id="status" value={form.data.status} onChange={(event) => form.setData('status', event.target.value)}>
                                    <option value="ACTIVE">Faol</option>
                                    <option value="INACTIVE">Nofaol</option>
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

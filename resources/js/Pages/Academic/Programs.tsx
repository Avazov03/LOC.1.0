import { useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import Modal, { ModalBody, ModalFooter } from '@/Components/Modal';
import { Button, Card, CardHeader, EmptyRow, Field, Input, Select, StatusBadge, Table, Td } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';

type Option = { id: number; name: string };
type Program = { id: number; name: string; status: string; faculty_id: number; faculty_name: string };

export default function Programs({ programs, faculties }: { programs: Program[]; faculties: Option[] }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Program | null>(null);
    const form = useForm<{ faculty_id: number | ''; name: string; status: string }>({ faculty_id: faculties[0]?.id ?? '', name: '', status: 'ACTIVE' });

    function openForm(program: Program | null) {
        setEditing(program);
        form.setData({
            faculty_id: program?.faculty_id ?? faculties[0]?.id ?? '',
            name: program?.name ?? '',
            status: program?.status ?? 'ACTIVE',
        });
        form.clearErrors();
        setOpen(true);
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) {
            form.put(`/academic/programs/${editing.id}`, options);
            return;
        }
        form.post('/academic/programs', options);
    }

    return (
        <AppLayout title="Yo‘nalishlar">
            <Card>
                <CardHeader
                    title="Yo‘nalishlar"
                    description={faculties.length === 0 ? 'Avval fakultet qo‘shing.' : 'Fakultet ichidagi ta’lim yo‘nalishlari.'}
                    action={
                        <Button icon="plus" onClick={() => openForm(null)} disabled={faculties.length === 0}>
                            Yangi yo‘nalish
                        </Button>
                    }
                />
                <Table head={['Nomi', 'Fakultet', 'Holat', '']}>
                    {programs.length === 0 ? (
                        <EmptyRow colSpan={4}>Yo‘nalish yo‘q.</EmptyRow>
                    ) : (
                        programs.map((program) => (
                            <tr key={program.id}>
                                <Td className="font-medium text-heading">{program.name}</Td>
                                <Td>{program.faculty_name}</Td>
                                <Td>
                                    <StatusBadge status={program.status} />
                                </Td>
                                <Td className="text-right">
                                    <Button size="sm" variant="tonal" icon="pencil" onClick={() => openForm(program)}>
                                        Tahrirlash
                                    </Button>
                                </Td>
                            </tr>
                        ))
                    )}
                </Table>
                <div className="h-2" />
            </Card>

            <Modal title={editing ? 'Yo‘nalishni tahrirlash' : 'Yangi yo‘nalish'} open={open} onClose={() => setOpen(false)}>
                <form onSubmit={submit} noValidate>
                    <ModalBody>
                        <Field label="Fakultet" htmlFor="faculty_id" error={form.errors.faculty_id}>
                            <Select id="faculty_id" value={form.data.faculty_id} onChange={(event) => form.setData('faculty_id', Number(event.target.value))}>
                                {faculties.map((faculty) => (
                                    <option key={faculty.id} value={faculty.id}>
                                        {faculty.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Nomi" htmlFor="name" error={form.errors.name}>
                            <Input id="name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} aria-invalid={form.errors.name ? true : undefined} />
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

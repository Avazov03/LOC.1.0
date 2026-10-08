import { useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import Modal, { ModalBody, ModalFooter } from '@/Components/Modal';
import { Button, Card, CardHeader, EmptyRow, Field, Input, Select, Table, Td } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';

type Option = { id: number; name: string };
type Group = { id: number; name: string; code: string; course: string; program: string; year: string };

export default function Groups({ groups, studyYears }: { groups: Group[]; studyYears: Option[] }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Group | null>(null);
    const form = useForm<{ study_year_id: number | ''; name: string; code: string }>({ study_year_id: studyYears[0]?.id ?? '', name: '', code: '' });

    function openForm(group: Group | null) {
        form.clearErrors();
        setEditing(group);
        form.setData(group ? { study_year_id: '', name: group.name, code: group.code } : { study_year_id: studyYears[0]?.id ?? '', name: '', code: '' });
        setOpen(true);
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) {
            form.transform((data) => ({ name: data.name, code: data.code }));
            form.put(`/academic/groups/${editing.id}`, options);
            return;
        }
        form.transform((data) => data);
        form.post('/academic/groups', options);
    }

    return (
        <AppLayout title="Guruhlar">
            <Card>
                <CardHeader
                    title="Guruhlar"
                    description={studyYears.length === 0 ? 'Avval kurs qo‘shing.' : 'Akademik guruh amaliyot joyi emas. Talabalar keyin turli tashkilotlarga biriktiriladi.'}
                    action={
                        <Button icon="plus" onClick={() => openForm(null)} disabled={studyYears.length === 0}>
                            Yangi guruh
                        </Button>
                    }
                />
                <Table head={['Guruh', 'Kod', 'Kurs', 'Yo‘nalish', 'O‘quv yili', '']}>
                    {groups.length === 0 ? (
                        <EmptyRow colSpan={6}>Guruh yo‘q.</EmptyRow>
                    ) : (
                        groups.map((group) => (
                            <tr key={group.id}>
                                <Td className="font-medium text-heading">{group.name}</Td>
                                <Td className="tabular-nums">{group.code}</Td>
                                <Td>{group.course}</Td>
                                <Td>{group.program}</Td>
                                <Td>{group.year}</Td>
                                <Td className="text-right">
                                    <Button size="sm" variant="tonal" icon="pencil" onClick={() => openForm(group)}>
                                        Tahrirlash
                                    </Button>
                                </Td>
                            </tr>
                        ))
                    )}
                </Table>
                <div className="h-2" />
            </Card>

            <Modal title={editing ? 'Guruhni tahrirlash' : 'Yangi guruh'} open={open} onClose={() => setOpen(false)}>
                <form onSubmit={submit} noValidate>
                    <ModalBody>
                        {editing ? (
                            <p className="text-sm text-muted">
                                Kurs: {editing.course} · {editing.program} · {editing.year}. Kurs o‘zgarmaydi: talabalar tarixi shu guruhga bog‘langan.
                            </p>
                        ) : (
                            <Field label="Kurs" htmlFor="study_year_id" error={form.errors.study_year_id}>
                                <Select id="study_year_id" value={form.data.study_year_id} onChange={(event) => form.setData('study_year_id', Number(event.target.value))}>
                                    {studyYears.map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.name}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                        )}
                        <div className="grid gap-5 sm:grid-cols-3">
                            <Field label="Nomi" htmlFor="name" error={form.errors.name} className="sm:col-span-2">
                                <Input id="name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} placeholder="403-guruh" />
                            </Field>
                            <Field label="Kod" htmlFor="code" error={form.errors.code}>
                                <Input id="code" value={form.data.code} onChange={(event) => form.setData('code', event.target.value)} placeholder="403" />
                            </Field>
                        </div>
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

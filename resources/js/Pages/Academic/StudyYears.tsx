import { useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import Modal, { ModalBody, ModalFooter } from '@/Components/Modal';
import { Button, Card, CardHeader, EmptyRow, Field, Input, Select, Table, Td } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';

type Option = { id: number; name: string };
type Row = { id: number; name: string; course_number: number; program_name: string; faculty_name: string; year_name: string };
type FormData = { program_id: number | ''; academic_year_id: number | ''; course_number: number; name: string };

export default function StudyYears({ studyYears, programs, years }: { studyYears: Row[]; programs: Option[]; years: Option[] }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Row | null>(null);
    const blank: FormData = { program_id: programs[0]?.id ?? '', academic_year_id: years[0]?.id ?? '', course_number: 1, name: '' };
    const form = useForm<FormData>(blank);
    const missing = programs.length === 0 || years.length === 0;

    function openForm(row: Row | null) {
        form.clearErrors();
        setEditing(row);
        form.setData(row ? { program_id: '', academic_year_id: '', course_number: row.course_number, name: row.name } : blank);
        setOpen(true);
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) {
            form.transform((data) => ({ course_number: data.course_number, name: data.name }));
            form.put(`/academic/study-years/${editing.id}`, options);
            return;
        }
        form.transform((data) => data);
        form.post('/academic/study-years', options);
    }

    return (
        <AppLayout title="Kurslar">
            <Card>
                <CardHeader
                    title="Kurslar"
                    description={missing ? 'Avval yo‘nalish va o‘quv yili qo‘shing.' : 'Yo‘nalish va o‘quv yili ichidagi kurs. Har yil uchun alohida yoziladi.'}
                    action={
                        <Button icon="plus" onClick={() => openForm(null)} disabled={missing}>
                            Yangi kurs
                        </Button>
                    }
                />
                <Table head={['Nomi', 'Kurs', 'Yo‘nalish', 'Fakultet', 'O‘quv yili', '']}>
                    {studyYears.length === 0 ? (
                        <EmptyRow colSpan={6}>Kurs yo‘q.</EmptyRow>
                    ) : (
                        studyYears.map((row) => (
                            <tr key={row.id}>
                                <Td className="font-medium text-heading">{row.name}</Td>
                                <Td className="tabular-nums">{row.course_number}</Td>
                                <Td>{row.program_name}</Td>
                                <Td>{row.faculty_name}</Td>
                                <Td>{row.year_name}</Td>
                                <Td className="text-right">
                                    <Button size="sm" variant="tonal" icon="pencil" onClick={() => openForm(row)}>
                                        Tahrirlash
                                    </Button>
                                </Td>
                            </tr>
                        ))
                    )}
                </Table>
                <div className="h-2" />
            </Card>

            <Modal title={editing ? 'Kursni tahrirlash' : 'Yangi kurs'} open={open} onClose={() => setOpen(false)}>
                <form onSubmit={submit} noValidate>
                    <ModalBody>
                        {editing ? (
                            <p className="text-sm text-muted">
                                {editing.program_name} · {editing.year_name}. Yo‘nalish va o‘quv yili o‘zgarmaydi: guruhlar va talabalar tarixi shunga bog‘langan.
                            </p>
                        ) : (
                            <>
                                <Field label="Yo‘nalish" htmlFor="program_id" error={form.errors.program_id}>
                                    <Select id="program_id" value={form.data.program_id} onChange={(event) => form.setData('program_id', Number(event.target.value))}>
                                        {programs.map((item) => (
                                            <option key={item.id} value={item.id}>
                                                {item.name}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field label="O‘quv yili" htmlFor="academic_year_id" error={form.errors.academic_year_id}>
                                    <Select id="academic_year_id" value={form.data.academic_year_id} onChange={(event) => form.setData('academic_year_id', Number(event.target.value))}>
                                        {years.map((item) => (
                                            <option key={item.id} value={item.id}>
                                                {item.name}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                            </>
                        )}
                        <div className="grid gap-5 sm:grid-cols-3">
                            <Field label="Raqam" htmlFor="course_number" error={form.errors.course_number}>
                                <Input id="course_number" type="number" min={1} max={8} value={form.data.course_number} onChange={(event) => form.setData('course_number', Number(event.target.value))} />
                            </Field>
                            <Field label="Nomi" htmlFor="name" error={form.errors.name} className="sm:col-span-2">
                                <Input id="name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} placeholder="4-kurs" />
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

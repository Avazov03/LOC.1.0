import { Link, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import Modal, { ModalBody, ModalFooter } from '@/Components/Modal';
import { Button, Card, CardHeader, EmptyRow, Field, Input, Pagination, StatusBadge, Table, Td } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';
import type { Paginated } from '@/types';

type Supervisor = {
    id: number;
    name: string;
    login: string;
    email: string | null;
    phone: string;
    position: string;
    status: string;
    open_internships_count: number;
};

type FormData = { name: string; login: string; email: string; password: string; phone: string; position: string };

const empty: FormData = { name: '', login: '', email: '', password: '', phone: '', position: '' };

export default function SupervisorsIndex({ supervisors, filters }: { supervisors: Paginated<Supervisor>; filters: { search: string | null } }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Supervisor | null>(null);
    const [search, setSearch] = useState(filters.search ?? '');
    const form = useForm<FormData>(empty);

    function openForm(supervisor: Supervisor | null) {
        setEditing(supervisor);
        form.setData(
            supervisor
                ? { name: supervisor.name, login: supervisor.login, email: supervisor.email ?? '', password: '', phone: supervisor.phone, position: supervisor.position }
                : empty,
        );
        form.clearErrors();
        setOpen(true);
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) {
            form.put(`/supervisors/${editing.id}`, options);
            return;
        }
        form.post('/supervisors', options);
    }

    function toggle(supervisor: Supervisor) {
        router.patch(`/supervisors/${supervisor.id}/status`, { status: supervisor.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE' }, { preserveScroll: true });
    }

    const field = (key: keyof FormData, label: string, type = 'text') => (
        <Field label={label} htmlFor={key} error={form.errors[key]}>
            <Input
                id={key}
                type={type}
                value={form.data[key]}
                onChange={(event) => form.setData(key, event.target.value)}
                autoComplete={type === 'password' ? 'new-password' : 'off'}
                aria-invalid={form.errors[key] ? true : undefined}
            />
        </Field>
    );

    return (
        <AppLayout title="Rahbarlar">
            <Card>
                <CardHeader
                    title="Amaliyot rahbarlari"
                    description="Nofaol rahbar tizimga kira olmaydi va yangi amaliyot guruhiga tanlanmaydi."
                    action={
                        <Button icon="plus" onClick={() => openForm(null)}>
                            Yangi rahbar
                        </Button>
                    }
                />
                <form
                    className="px-6 pb-4"
                    role="search"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get('/supervisors', { search: search || undefined }, { preserveState: true, replace: true });
                    }}
                >
                    <Input className="max-w-xs" placeholder="Ism yoki login" value={search} onChange={(event) => setSearch(event.target.value)} aria-label="Qidirish" />
                </form>
                <Table head={['F.I.Sh.', 'Login', 'Telefon', 'Lavozim', 'Guruhlar', 'Holat', '']}>
                    {supervisors.data.length === 0 ? (
                        <EmptyRow colSpan={7}>Rahbar yo‘q.</EmptyRow>
                    ) : (
                        supervisors.data.map((supervisor) => (
                            <tr key={supervisor.id}>
                                <Td className="font-medium text-heading">
                                    <Link href={`/supervisors/${supervisor.id}`} className="hover:text-primary-600">
                                        {supervisor.name}
                                    </Link>
                                    {supervisor.email ? <span className="block text-xs font-normal text-muted">{supervisor.email}</span> : null}
                                </Td>
                                <Td>{supervisor.login}</Td>
                                <Td>{supervisor.phone}</Td>
                                <Td>{supervisor.position}</Td>
                                <Td>{supervisor.open_internships_count}</Td>
                                <Td>
                                    <StatusBadge status={supervisor.status} />
                                </Td>
                                <Td className="text-right whitespace-nowrap">
                                    <Button size="sm" variant="tonal" icon="pencil" onClick={() => openForm(supervisor)}>
                                        Tahrirlash
                                    </Button>{' '}
                                    <Button size="sm" variant="secondary" onClick={() => toggle(supervisor)}>
                                        {supervisor.status === 'ACTIVE' ? 'Nofaol qilish' : 'Faollashtirish'}
                                    </Button>
                                </Td>
                            </tr>
                        ))
                    )}
                </Table>
                <Pagination page={supervisors} />
            </Card>

            <Modal title={editing ? 'Rahbarni tahrirlash' : 'Yangi rahbar'} open={open} onClose={() => setOpen(false)}>
                <form onSubmit={submit} noValidate>
                    <ModalBody>
                        {field('name', 'F.I.Sh.')}
                        <div className="grid gap-4 sm:grid-cols-2">
                            {field('login', 'Login')}
                            {field('password', editing ? 'Yangi parol (ixtiyoriy)' : 'Parol', 'password')}
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {field('phone', 'Telefon')}
                            {field('email', 'Email (ixtiyoriy)', 'email')}
                        </div>
                        {field('position', 'Lavozim')}
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

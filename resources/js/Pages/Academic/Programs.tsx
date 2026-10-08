import Modal from '@/Components/Modal';
import AppLayout from '@/Layouts/AppLayout';
import { useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Option = { id: number; name: string };
type Program = { id: number; name: string; status: string; faculty_id: number; faculty_name: string };

export default function Programs({ programs, faculties }: { programs: Program[]; faculties: Option[] }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Program | null>(null);
    const form = useForm({ faculty_id: faculties[0]?.id ?? '', name: '', status: 'ACTIVE' });

    function startCreate() {
        setEditing(null);
        form.setData({ faculty_id: faculties[0]?.id ?? '', name: '', status: 'ACTIVE' });
        form.clearErrors();
        setOpen(true);
    }

    function startEdit(program: Program) {
        setEditing(program);
        form.setData({ faculty_id: program.faculty_id, name: program.name, status: program.status });
        form.clearErrors();
        setOpen(true);
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        const options = { onSuccess: () => setOpen(false) };
        if (editing) {
            form.put(`/academic/programs/${editing.id}`, options);
            return;
        }
        form.post('/academic/programs', options);
    }

    return (
        <AppLayout title="Yo‘nalishlar">
            <div className="card">
                <div className="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 className="card-title mb-1">Yo‘nalishlar</h5>
                        <p className="mb-0 text-body-secondary small">Fakultet ichidagi ta’lim yo‘nalishlari</p>
                    </div>
                    <button type="button" className="btn btn-primary" onClick={startCreate} disabled={faculties.length === 0}>
                        <i className="bx bx-plus me-1" /> Yangi
                    </button>
                </div>
                <div className="table-responsive">
                    <table className="table table-hover">
                        <thead><tr><th>Nomi</th><th>Fakultet</th><th>Holat</th><th /></tr></thead>
                        <tbody>
                            {programs.length === 0 ? (
                                <tr><td colSpan={4} className="text-center text-body-secondary py-5">Yo‘nalish yo‘q.</td></tr>
                            ) : programs.map((program) => (
                                <tr key={program.id}>
                                    <td className="fw-medium">{program.name}</td>
                                    <td>{program.faculty_name}</td>
                                    <td><span className={`badge bg-label-${program.status === 'ACTIVE' ? 'success' : 'secondary'}`}>{program.status === 'ACTIVE' ? 'Faol' : 'Nofaol'}</span></td>
                                    <td className="text-end"><button type="button" className="btn btn-sm btn-label-primary" onClick={() => startEdit(program)}>Tahrirlash</button></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            <Modal title={editing ? 'Yo‘nalishni tahrirlash' : 'Yangi yo‘nalish'} open={open} onClose={() => setOpen(false)}>
                <form onSubmit={submit}>
                    <div className="modal-body">
                        <label className="form-label">Fakultet</label>
                        <select className="form-select mb-4" value={form.data.faculty_id} onChange={(event) => form.setData('faculty_id', Number(event.target.value))}>
                            {faculties.map((faculty) => <option key={faculty.id} value={faculty.id}>{faculty.name}</option>)}
                        </select>
                        <label className="form-label">Nomi</label>
                        <input className="form-control" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
                        {form.errors.name ? <div className="text-danger small mt-1">{form.errors.name}</div> : null}
                        {form.errors.faculty_id ? <div className="text-danger small mt-1">{form.errors.faculty_id}</div> : null}
                        {editing ? (
                            <div className="mt-4">
                                <label className="form-label">Holat</label>
                                <select className="form-select" value={form.data.status} onChange={(event) => form.setData('status', event.target.value)}>
                                    <option value="ACTIVE">Faol</option>
                                    <option value="INACTIVE">Nofaol</option>
                                </select>
                            </div>
                        ) : null}
                    </div>
                    <div className="modal-footer">
                        <button type="button" className="btn btn-label-secondary" onClick={() => setOpen(false)}>Bekor</button>
                        <button className="btn btn-primary" disabled={form.processing}>Saqlash</button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}

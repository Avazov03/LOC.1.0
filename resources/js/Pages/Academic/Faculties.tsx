import Modal from '@/Components/Modal';
import AppLayout from '@/Layouts/AppLayout';
import { useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Faculty = { id: number; name: string; status: string; programs_count: number };

export default function Faculties({ faculties }: { faculties: Faculty[] }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Faculty | null>(null);
    const form = useForm({ name: '', status: 'ACTIVE' });

    function startCreate() {
        setEditing(null);
        form.setData({ name: '', status: 'ACTIVE' });
        form.clearErrors();
        setOpen(true);
    }

    function startEdit(faculty: Faculty) {
        setEditing(faculty);
        form.setData({ name: faculty.name, status: faculty.status });
        form.clearErrors();
        setOpen(true);
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        const options = { onSuccess: () => setOpen(false) };
        if (editing) {
            form.put(`/academic/faculties/${editing.id}`, options);
            return;
        }
        form.post('/academic/faculties', options);
    }

    return (
        <AppLayout title="Fakultetlar">
            <div className="card">
                <div className="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 className="card-title mb-1">Fakultetlar</h5>
                        <p className="mb-0 text-body-secondary small">Universitet tarkibidagi fakultetlar</p>
                    </div>
                    <button type="button" className="btn btn-primary" onClick={startCreate}>
                        <i className="bx bx-plus me-1" /> Yangi
                    </button>
                </div>
                <div className="table-responsive">
                    <table className="table table-hover">
                        <thead>
                            <tr>
                                <th>Nomi</th>
                                <th>Yo‘nalishlar</th>
                                <th>Holat</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {faculties.length === 0 ? (
                                <tr>
                                    <td colSpan={4} className="text-center text-body-secondary py-5">Fakultet yo‘q. Yangi tugmasi bilan qo‘shing.</td>
                                </tr>
                            ) : faculties.map((faculty) => (
                                <tr key={faculty.id}>
                                    <td className="fw-medium">{faculty.name}</td>
                                    <td>{faculty.programs_count}</td>
                                    <td><span className={`badge bg-label-${faculty.status === 'ACTIVE' ? 'success' : 'secondary'}`}>{faculty.status === 'ACTIVE' ? 'Faol' : 'Nofaol'}</span></td>
                                    <td className="text-end"><button type="button" className="btn btn-sm btn-label-primary" onClick={() => startEdit(faculty)}>Tahrirlash</button></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            <Modal title={editing ? 'Fakultetni tahrirlash' : 'Yangi fakultet'} open={open} onClose={() => setOpen(false)}>
                <form onSubmit={submit}>
                    <div className="modal-body">
                        <label className="form-label" htmlFor="name">Nomi</label>
                        <input id="name" className="form-control" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
                        {form.errors.name ? <div className="text-danger small mt-1">{form.errors.name}</div> : null}
                        {editing ? (
                            <div className="mt-4">
                                <label className="form-label" htmlFor="status">Holat</label>
                                <select id="status" className="form-select" value={form.data.status} onChange={(event) => form.setData('status', event.target.value)}>
                                    <option value="ACTIVE">Faol</option>
                                    <option value="INACTIVE">Nofaol</option>
                                </select>
                            </div>
                        ) : null}
                    </div>
                    <div className="modal-footer">
                        <button type="button" className="btn btn-label-secondary" onClick={() => setOpen(false)}>Bekor</button>
                        <button type="submit" className="btn btn-primary" disabled={form.processing}>Saqlash</button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}

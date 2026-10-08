import Modal from '@/Components/Modal';
import AppLayout from '@/Layouts/AppLayout';
import { useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Year = { id: number; name: string; starts_on: string; ends_on: string; status: string };

export default function Years({ years }: { years: Year[] }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Year | null>(null);
    const form = useForm({ name: '', starts_on: '', ends_on: '', status: 'ACTIVE' });

    function startCreate() {
        setEditing(null);
        form.setData({ name: '', starts_on: '', ends_on: '', status: 'ACTIVE' });
        form.clearErrors();
        setOpen(true);
    }

    function startEdit(year: Year) {
        setEditing(year);
        form.setData({ name: year.name, starts_on: year.starts_on, ends_on: year.ends_on, status: year.status });
        form.clearErrors();
        setOpen(true);
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        const options = { onSuccess: () => setOpen(false) };
        if (editing) {
            form.put(`/academic/years/${editing.id}`, options);
            return;
        }
        form.post('/academic/years', options);
    }

    return (
        <AppLayout title="O‘quv yillari">
            <div className="card">
                <div className="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 className="card-title mb-1">O‘quv yillari</h5>
                        <p className="mb-0 text-body-secondary small">Masalan 2026/2027. Eski yil yozuvlari o‘chirilmaydi.</p>
                    </div>
                    <button type="button" className="btn btn-primary" onClick={startCreate}><i className="bx bx-plus me-1" /> Yangi</button>
                </div>
                <div className="table-responsive">
                    <table className="table table-hover">
                        <thead><tr><th>Nomi</th><th>Boshlanish</th><th>Tugash</th><th>Holat</th><th /></tr></thead>
                        <tbody>
                            {years.length === 0 ? <tr><td colSpan={5} className="text-center text-body-secondary py-5">O‘quv yili yo‘q.</td></tr> : years.map((year) => (
                                <tr key={year.id}>
                                    <td className="fw-medium">{year.name}</td>
                                    <td>{year.starts_on}</td>
                                    <td>{year.ends_on}</td>
                                    <td><span className={`badge bg-label-${year.status === 'ACTIVE' ? 'success' : 'secondary'}`}>{year.status === 'ACTIVE' ? 'Faol' : 'Yopilgan'}</span></td>
                                    <td className="text-end"><button type="button" className="btn btn-sm btn-label-primary" onClick={() => startEdit(year)}>Tahrirlash</button></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            <Modal title={editing ? 'O‘quv yilini tahrirlash' : 'Yangi o‘quv yili'} open={open} onClose={() => setOpen(false)}>
                <form onSubmit={submit}>
                    <div className="modal-body">
                        <label className="form-label">Nomi</label>
                        <input className="form-control mb-4" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} placeholder="2026/2027" />
                        {form.errors.name ? <div className="text-danger small mb-3">{form.errors.name}</div> : null}
                        <div className="row">
                            <div className="col-md-6 mb-4">
                                <label className="form-label">Boshlanish</label>
                                <input type="date" className="form-control" value={form.data.starts_on} onChange={(event) => form.setData('starts_on', event.target.value)} />
                            </div>
                            <div className="col-md-6 mb-4">
                                <label className="form-label">Tugash</label>
                                <input type="date" className="form-control" value={form.data.ends_on} onChange={(event) => form.setData('ends_on', event.target.value)} />
                                {form.errors.ends_on ? <div className="text-danger small mt-1">{form.errors.ends_on}</div> : null}
                            </div>
                        </div>
                        {editing ? (
                            <select className="form-select" value={form.data.status} onChange={(event) => form.setData('status', event.target.value)}>
                                <option value="ACTIVE">Faol</option>
                                <option value="CLOSED">Yopilgan</option>
                            </select>
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

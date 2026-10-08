import Modal from '@/Components/Modal';
import AppLayout from '@/Layouts/AppLayout';
import { useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Option = { id: number; name: string };
type Group = { id: number; name: string; code: string; course: string; program: string; year: string };

export default function Groups({ groups, studyYears }: { groups: Group[]; studyYears: Option[] }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ study_year_id: studyYears[0]?.id ?? '', name: '', code: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/academic/groups', { onSuccess: () => setOpen(false) });
    }

    return (
        <AppLayout title="Guruhlar">
            <div className="card">
                <div className="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 className="card-title mb-1">Guruhlar</h5>
                        <p className="mb-0 text-body-secondary small">Guruh amaliyot joyi emas. Talabalar keyin turli tashkilotlarga biriktiriladi.</p>
                    </div>
                    <button type="button" className="btn btn-primary" disabled={studyYears.length === 0} onClick={() => { form.clearErrors(); setOpen(true); }}>
                        <i className="bx bx-plus me-1" /> Yangi
                    </button>
                </div>
                <div className="table-responsive">
                    <table className="table table-hover">
                        <thead><tr><th>Guruh</th><th>Kod</th><th>Kurs</th><th>Yo‘nalish</th><th>Yil</th></tr></thead>
                        <tbody>
                            {groups.length === 0 ? <tr><td colSpan={5} className="text-center text-body-secondary py-5">Guruh yo‘q.</td></tr> : groups.map((group) => (
                                <tr key={group.id}>
                                    <td className="fw-medium">{group.name}</td>
                                    <td>{group.code}</td>
                                    <td>{group.course}</td>
                                    <td>{group.program}</td>
                                    <td>{group.year}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            <Modal title="Yangi guruh" open={open} onClose={() => setOpen(false)}>
                <form onSubmit={submit}>
                    <div className="modal-body">
                        <label className="form-label">Kurs</label>
                        <select className="form-select mb-4" value={form.data.study_year_id} onChange={(event) => form.setData('study_year_id', Number(event.target.value))}>
                            {studyYears.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                        </select>
                        <label className="form-label">Nomi</label>
                        <input className="form-control mb-4" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} placeholder="403-guruh" />
                        {form.errors.name ? <div className="text-danger small mb-3">{form.errors.name}</div> : null}
                        <label className="form-label">Kod</label>
                        <input className="form-control" value={form.data.code} onChange={(event) => form.setData('code', event.target.value)} placeholder="403" />
                        {form.errors.code ? <div className="text-danger small mt-1">{form.errors.code}</div> : null}
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

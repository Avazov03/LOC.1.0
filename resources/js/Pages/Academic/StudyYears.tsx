import Modal from '@/Components/Modal';
import AppLayout from '@/Layouts/AppLayout';
import { useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

type Option = { id: number; name: string };
type Row = { id: number; name: string; course_number: number; program_name: string; faculty_name: string; year_name: string };

export default function StudyYears({ studyYears, programs, years }: { studyYears: Row[]; programs: Option[]; years: Option[] }) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        program_id: programs[0]?.id ?? '',
        academic_year_id: years[0]?.id ?? '',
        course_number: 1,
        name: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/academic/study-years', { onSuccess: () => setOpen(false) });
    }

    return (
        <AppLayout title="Kurslar">
            <div className="card">
                <div className="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 className="card-title mb-1">Kurslar</h5>
                        <p className="mb-0 text-body-secondary small">Yo‘nalish va o‘quv yili ichidagi kurs. Raqam kodga yozilmaydi.</p>
                    </div>
                    <button type="button" className="btn btn-primary" disabled={programs.length === 0 || years.length === 0} onClick={() => { form.clearErrors(); setOpen(true); }}>
                        <i className="bx bx-plus me-1" /> Yangi
                    </button>
                </div>
                <div className="table-responsive">
                    <table className="table table-hover">
                        <thead><tr><th>Nomi</th><th>Kurs</th><th>Yo‘nalish</th><th>Fakultet</th><th>Yil</th></tr></thead>
                        <tbody>
                            {studyYears.length === 0 ? <tr><td colSpan={5} className="text-center text-body-secondary py-5">Kurs yo‘q.</td></tr> : studyYears.map((row) => (
                                <tr key={row.id}>
                                    <td className="fw-medium">{row.name}</td>
                                    <td>{row.course_number}</td>
                                    <td>{row.program_name}</td>
                                    <td>{row.faculty_name}</td>
                                    <td>{row.year_name}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            <Modal title="Yangi kurs" open={open} onClose={() => setOpen(false)}>
                <form onSubmit={submit}>
                    <div className="modal-body">
                        <label className="form-label">Yo‘nalish</label>
                        <select className="form-select mb-4" value={form.data.program_id} onChange={(event) => form.setData('program_id', Number(event.target.value))}>
                            {programs.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                        </select>
                        <label className="form-label">O‘quv yili</label>
                        <select className="form-select mb-4" value={form.data.academic_year_id} onChange={(event) => form.setData('academic_year_id', Number(event.target.value))}>
                            {years.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                        </select>
                        <div className="row">
                            <div className="col-4">
                                <label className="form-label">Raqam</label>
                                <input type="number" min={1} max={8} className="form-control" value={form.data.course_number} onChange={(event) => form.setData('course_number', Number(event.target.value))} />
                                {form.errors.course_number ? <div className="text-danger small mt-1">{form.errors.course_number}</div> : null}
                            </div>
                            <div className="col-8">
                                <label className="form-label">Nomi</label>
                                <input className="form-control" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} placeholder="4-kurs" />
                                {form.errors.name ? <div className="text-danger small mt-1">{form.errors.name}</div> : null}
                            </div>
                        </div>
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

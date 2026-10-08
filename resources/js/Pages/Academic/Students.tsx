import AppLayout from '@/Layouts/AppLayout';

type Student = { id: number; name: string; phone: string; student_code: string | null; group: string | null; status: string };

const labels: Record<string, string> = { ACTIVE: 'Faol', INACTIVE: 'Nofaol', BLOCKED: 'Bloklangan' };

export default function Students({ students }: { students: Student[] }) {
    return (
        <AppLayout title="Talabalar">
            <div className="card">
                <div className="card-header">
                    <h5 className="card-title mb-1">Talabalar</h5>
                    <p className="mb-0 text-body-secondary small">Talaba o‘zi tizimga Telegram taklif havolasi orqali kiradi. Bu yerda faqat ro‘yxat.</p>
                </div>
                <div className="table-responsive">
                    <table className="table table-hover">
                        <thead><tr><th>F.I.Sh.</th><th>Telefon</th><th>ID</th><th>Guruh</th><th>Holat</th></tr></thead>
                        <tbody>
                            {students.length === 0 ? (
                                <tr><td colSpan={5} className="text-center text-body-secondary py-5">Hali talaba yo‘q. Taklif havolasi keyingi bosqichda ochiladi.</td></tr>
                            ) : students.map((student) => (
                                <tr key={student.id}>
                                    <td className="fw-medium">{student.name}</td>
                                    <td>{student.phone}</td>
                                    <td>{student.student_code ?? '—'}</td>
                                    <td>{student.group ?? '—'}</td>
                                    <td>{labels[student.status] ?? student.status}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}

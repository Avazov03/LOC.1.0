import StudentList from '@/Components/StudentList';
import { Card, CardHeader } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';
import type { Option, Paginated, StudentFilters, StudentRow } from '@/types';

export default function SupervisorStudents({ students, filters, internships }: { students: Paginated<StudentRow>; filters: StudentFilters; internships: Option[] }) {
    return (
        <AppLayout title="Talabalar">
            <Card>
                <CardHeader title="Talabalar" description="Faqat sizga hozir biriktirilgan amaliyot guruhlaridagi talabalar." />
                <StudentList
                    url="/my-students"
                    detailUrl={(id) => `/students/${id}`}
                    students={students}
                    filters={filters}
                    internships={internships}
                    emptyText="Guruhlaringizda hali talaba yo‘q."
                />
                <div className="h-2" />
            </Card>
        </AppLayout>
    );
}

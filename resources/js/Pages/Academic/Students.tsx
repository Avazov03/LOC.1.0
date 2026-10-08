import StudentList from '@/Components/StudentList';
import { Card, CardHeader } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';
import type { Option, Paginated, StudentFilters, StudentRow } from '@/types';

export default function Students({
    students,
    filters,
    groups,
    internships,
}: {
    students: Paginated<StudentRow>;
    filters: StudentFilters;
    groups: Option[];
    internships: Option[];
}) {
    return (
        <AppLayout title="Talabalar">
            <Card>
                <CardHeader
                    title="Talabalar"
                    description="Talaba tizimga Telegram taklif havolasi orqali o‘zi qo‘shiladi. Ma’lumotni tuzatish va holatni o‘zgartirish audit jurnaliga yoziladi; talaba o‘chirilmaydi."
                />
                <StudentList
                    url="/academic/students"
                    detailUrl={(id) => `/academic/students/${id}`}
                    students={students}
                    filters={filters}
                    groups={groups}
                    internships={internships}
                    emptyText="Hali talaba yo‘q. Amaliyot guruhi sahifasida taklif havolasini yarating va talabalarga yuboring."
                />
                <div className="h-2" />
            </Card>
        </AppLayout>
    );
}

import { router } from '@inertiajs/react';
import ChangeRequestTable from '@/Components/ChangeRequestTable';
import { Card, CardHeader, Pagination, Select } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';
import type { ChangeRequest, Paginated, Role } from '@/types';

export default function ChangeRequestsIndex({ requests, filters, role }: { requests: Paginated<ChangeRequest>; filters: { status: string | null }; role: Role }) {
    return (
        <AppLayout title="O‘zgartirish so‘rovlari">
            <Card>
                <CardHeader
                    title="Joy o‘zgartirish so‘rovlari"
                    description={
                        role === 'ADMIN'
                            ? 'Mavjud tashkilot so‘rovini rahbar yoki siz tasdiqlaysiz. Yangi tashkilot so‘rovi faqat siz tashkilotni joylashuvi bilan yaratganingizda tasdiqlanadi.'
                            : 'Mavjud tashkilotga o‘tish so‘rovlarini tasdiqlashingiz yoki rad etishingiz mumkin. Yangi tashkilot so‘rovlarini administrator ko‘rib chiqadi.'
                    }
                    action={
                        <Select
                            className="w-48"
                            value={filters.status ?? ''}
                            onChange={(event) => router.get('/change-requests', { status: event.target.value || undefined }, { preserveState: true, replace: true })}
                            aria-label="Holat"
                        >
                            <option value="">Barcha holatlar</option>
                            <option value="PENDING">Kutilmoqda</option>
                            <option value="APPROVED">Tasdiqlangan</option>
                            <option value="REJECTED">Rad etilgan</option>
                            <option value="CANCELLED">Bekor qilingan</option>
                        </Select>
                    }
                />
                <ChangeRequestTable requests={requests.data} role={role} />
                <Pagination page={requests} />
            </Card>
        </AppLayout>
    );
}

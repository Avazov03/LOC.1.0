import { router } from '@inertiajs/react';
import AuditTable from '@/Components/AuditTable';
import { Card, CardHeader, Pagination, Select } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';
import type { AuditEntry, Paginated } from '@/types';

export default function AuditLogsIndex({ logs, filters, entities }: { logs: Paginated<AuditEntry>; filters: { entity: string | null }; entities: string[] }) {
    return (
        <AppLayout title="Audit jurnali">
            <Card>
                <CardHeader
                    title="Audit jurnali"
                    description="Yozuvlar faqat qo‘shiladi: tahrirlab ham, o‘chirib ham bo‘lmaydi."
                    action={
                        <Select
                            className="w-full sm:w-64"
                            value={filters.entity ?? ''}
                            onChange={(event) => router.get('/audit-logs', { entity: event.target.value || undefined }, { preserveState: true, replace: true })}
                            aria-label="Obyekt turi"
                        >
                            <option value="">Barcha obyektlar</option>
                            {entities.map((entity) => (
                                <option key={entity} value={entity}>
                                    {entity}
                                </option>
                            ))}
                        </Select>
                    }
                />
                <AuditTable logs={logs.data} />
                <Pagination page={logs} />
            </Card>
        </AppLayout>
    );
}

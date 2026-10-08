import { Link } from '@inertiajs/react';
import { AttendanceFilterBar, DayStatusBadge, duration } from '@/Components/AttendanceUi';
import { Badge, Card, CardHeader, EmptyRow, Pagination, Table, Td } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';
import type { AttendanceFilterOptions, AttendanceFilterValues, DayStatus, Paginated, StatusOption } from '@/types';

type Row = {
    student_id: number;
    name: string;
    group: string | null;
    organization: string | null;
    status: DayStatus | null;
    first_check_in: string | null;
    last_check_out: string | null;
    completed_seconds: number;
    open: boolean;
    failed_count: number;
};

type Totals = Record<DayStatus | 'EXPECTED', number>;

const cards: Array<{ key: DayStatus; label: string; className: string }> = [
    { key: 'PRESENT', label: 'Keldi', className: 'text-[#56ca00] dark:text-success' },
    { key: 'PARTIAL', label: 'Qisman', className: 'text-[#00a7cc] dark:text-info' },
    { key: 'INCOMPLETE', label: 'Yakunlanmagan', className: 'text-[#e09600] dark:text-warning' },
    { key: 'LOCATION_REJECTED', label: 'Joylashuv rad etildi', className: 'text-danger' },
    { key: 'ABSENT', label: 'Kelmadi', className: 'text-secondary' },
];

export default function AttendanceIndex({
    filters,
    options,
    totals,
    rows,
    today,
    statuses,
}: {
    filters: AttendanceFilterValues;
    options: AttendanceFilterOptions;
    totals: Totals;
    rows: Paginated<Row>;
    today: string;
    statuses: StatusOption[];
}) {
    return (
        <AppLayout title="Davomat">
            <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-6">
                <Card className="p-4">
                    <p className="text-xs text-muted">Kutilgan</p>
                    <p className="mt-1 text-2xl font-semibold text-heading tabular-nums">{totals.EXPECTED}</p>
                </Card>
                {cards.map((card) => (
                    <Card key={card.key} className="p-4">
                        <p className="text-xs text-muted">{card.label}</p>
                        <p className={`mt-1 text-2xl font-semibold tabular-nums ${card.className}`}>{totals[card.key]}</p>
                    </Card>
                ))}
            </div>

            <Card>
                <CardHeader
                    title={`Kunlik davomat · ${filters.date}`}
                    description="Holat server vaqti va tasdiqlangan joylashuv bo‘yicha hisoblanadi. Talabani bosib, dalillar va urinishlarni ko‘ring."
                />
                <AttendanceFilterBar url="/attendance" filters={filters} options={options} statuses={statuses} max={today} />
                <Table head={['Talaba', 'Guruh', 'Tashkilot', 'Kelish', 'Ketish', 'Davomiylik', 'Holat', '']}>
                    {rows.data.length === 0 ? (
                        <EmptyRow colSpan={8}>Bu sana va filtr bo‘yicha davomat yozuvi yo‘q.</EmptyRow>
                    ) : (
                        rows.data.map((row) => (
                            <tr key={row.student_id}>
                                <Td className="font-medium text-heading">
                                    <Link href={`/attendance/students/${row.student_id}?from=${filters.date}&to=${filters.date}`} className="hover:text-primary-600">
                                        {row.name}
                                    </Link>
                                </Td>
                                <Td>{row.group ?? '—'}</Td>
                                <Td>{row.organization ?? '—'}</Td>
                                <Td className="tabular-nums">{row.first_check_in ?? '—'}</Td>
                                <Td className="tabular-nums">{row.last_check_out ?? (row.open ? <Badge tone="warning">ochiq</Badge> : '—')}</Td>
                                <Td className="tabular-nums whitespace-nowrap">{duration(row.completed_seconds)}</Td>
                                <Td>
                                    <DayStatusBadge status={row.status} />
                                    {row.failed_count > 0 ? <span className="mt-1 block text-xs text-danger">{row.failed_count} ta rad etilgan urinish</span> : null}
                                </Td>
                                <Td className="text-right">
                                    <Link
                                        href={`/attendance/students/${row.student_id}`}
                                        className="inline-flex items-center rounded-md bg-primary-500/15 px-3 py-1.5 text-[0.8125rem] font-medium text-primary-600 hover:bg-primary-500/25 dark:text-primary-300"
                                    >
                                        Tarix
                                    </Link>
                                </Td>
                            </tr>
                        ))
                    )}
                </Table>
                <Pagination page={rows} />
            </Card>
        </AppLayout>
    );
}

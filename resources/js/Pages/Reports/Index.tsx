import { Link, router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { AttendanceFilterBar, duration } from '@/Components/AttendanceUi';
import Icon from '@/Components/Icon';
import { Badge, Button, Card, CardHeader, EmptyRow, Pagination, Table, Td } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';
import type { AttendanceFilterOptions, AttendanceFilterValues, Paginated, SharedProps } from '@/types';

type Row = {
    student_id: number;
    name: string;
    group: string | null;
    organization: string | null;
    present: number;
    marked: number;
    excused: number;
    incomplete: number;
    rejected: number;
    absent: number;
    failed: number;
    seconds: number;
};
type Totals = { students: number; present: number; marked: number; excused: number; incomplete: number; rejected: number; absent: number; failed: number; seconds: number };
type Export = { id: number; status: 'PENDING' | 'RUNNING' | 'DONE' | 'FAILED'; type: string; from: string | null; to: string | null; rows: number | null; created_at: string };

const exportTone = { PENDING: 'warning', RUNNING: 'info', DONE: 'success', FAILED: 'danger' } as const;
const exportLabel = { PENDING: 'Navbatda', RUNNING: 'Tayyorlanmoqda', DONE: 'Tayyor', FAILED: 'Xato' } as const;

export default function Reports({
    filters,
    options,
    totals,
    rows,
    exports,
    maxDays,
    today,
}: {
    filters: AttendanceFilterValues;
    options: AttendanceFilterOptions;
    totals: Totals;
    rows: Paginated<Row>;
    exports: Export[];
    maxDays: number;
    today: string;
}) {
    const { auth } = usePage<SharedProps>().props;
    const waiting = exports.some((item) => item.status === 'PENDING' || item.status === 'RUNNING');

    useEffect(() => {
        if (!waiting) {
            return;
        }
        const timer = window.setInterval(() => router.reload({ only: ['exports'] }), 4000);

        return () => window.clearInterval(timer);
    }, [waiting]);

    function exportCsv(type: 'summary' | 'daily') {
        const query = Object.fromEntries(Object.entries({ ...filters, type }).filter(([, value]) => value !== null && value !== '' && value !== undefined));
        router.post('/reports/export', query, { preserveScroll: true });
    }

    const tiles: Array<[string, number | string]> = [
        ['Talabalar', totals.students],
        ['Keldi (kun)', totals.present],
        ['shundan rahbar belgilagan', totals.marked],
        ['Sababli', totals.excused],
        ['Yakunlanmagan', totals.incomplete],
        ['Joylashuv rad etildi', totals.rejected],
        ['Kelmadi', totals.absent],
        ['Rad etilgan urinish', totals.failed],
        ['Jami vaqt', duration(totals.seconds)],
    ];

    return (
        <AppLayout title="Hisobotlar">
            <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-5">
                {tiles.map(([label, value]) => (
                    <Card key={label} className="p-4">
                        <p className="text-xs text-muted">{label}</p>
                        <p className="mt-1 text-xl font-semibold text-heading tabular-nums">{value}</p>
                    </Card>
                ))}
            </div>

            <Card>
                <CardHeader
                    title={`Davomat hisoboti · ${filters.from} — ${filters.to}`}
                    description={`${auth.user?.role === 'ADMIN' ? 'Universitet' : 'Sizning talabalaringiz'} bo‘yicha. Faqat amaliyot kunlari hisoblanadi; oraliq ko‘pi bilan ${maxDays} kun.`}
                    action={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="tonal" icon="download" onClick={() => exportCsv('summary')}>CSV (jamlanma)</Button>
                            <Button variant="tonal" icon="download" onClick={() => exportCsv('daily')}>CSV (kunlik)</Button>
                        </div>
                    }
                />
                <AttendanceFilterBar url="/reports" filters={filters} options={options} statuses={[]} range max={today} />
                <Table head={['Talaba', 'Guruh', 'Tashkilot', 'Keldi', 'Sababli', 'Yakunl.', 'Rad etildi', 'Kelmadi', 'Urinish', 'Jami vaqt']}>
                    {rows.data.length === 0 ? (
                        <EmptyRow colSpan={10}>Bu oraliq va filtr bo‘yicha ma’lumot yo‘q.</EmptyRow>
                    ) : (
                        rows.data.map((row) => (
                            <tr key={row.student_id}>
                                <Td className="font-medium text-heading">
                                    <Link href={`/attendance/students/${row.student_id}?from=${filters.from}&to=${filters.to}`} className="hover:text-primary-600">
                                        {row.name}
                                    </Link>
                                </Td>
                                <Td>{row.group ?? '—'}</Td>
                                <Td>{row.organization ?? '—'}</Td>
                                <Td className="tabular-nums">
                                    {row.present}
                                    {row.marked > 0 ? <span className="block text-xs text-muted">{row.marked} tasi rahbar belgilagan</span> : null}
                                </Td>
                                <Td className="tabular-nums">{row.excused}</Td>
                                <Td className="tabular-nums">{row.incomplete}</Td>
                                <Td className="tabular-nums">{row.rejected}</Td>
                                <Td className="tabular-nums">{row.absent}</Td>
                                <Td className="tabular-nums">{row.failed}</Td>
                                <Td className="tabular-nums whitespace-nowrap">{duration(row.seconds)}</Td>
                            </tr>
                        ))
                    )}
                </Table>
                <Pagination page={rows} />
            </Card>

            <Card className="mt-6">
                <CardHeader title="Mening eksportlarim" description="Fayl faqat uni so‘ragan foydalanuvchiga ko‘rinadi." />
                <Table head={['So‘ralgan', 'Turi', 'Oraliq', 'Qatorlar', 'Holat', '']}>
                    {exports.length === 0 ? (
                        <EmptyRow colSpan={6}>Hali eksport yo‘q.</EmptyRow>
                    ) : (
                        exports.map((item) => (
                            <tr key={item.id}>
                                <Td className="tabular-nums whitespace-nowrap">{item.created_at}</Td>
                                <Td>{item.type === 'daily' ? 'Kunlik' : 'Jamlanma'}</Td>
                                <Td className="tabular-nums whitespace-nowrap">{item.from} — {item.to}</Td>
                                <Td className="tabular-nums">{item.rows ?? '—'}</Td>
                                <Td><Badge tone={exportTone[item.status]}>{exportLabel[item.status]}</Badge></Td>
                                <Td className="text-right">
                                    {item.status === 'DONE' ? (
                                        <a
                                            href={`/reports/exports/${item.id}/download`}
                                            className="inline-flex items-center gap-1.5 rounded-md bg-primary-500/15 px-3 py-1.5 text-[0.8125rem] font-medium text-primary-600 hover:bg-primary-500/25 dark:text-primary-300"
                                        >
                                            <Icon name="download" className="size-4" /> Yuklab olish
                                        </a>
                                    ) : null}
                                </Td>
                            </tr>
                        ))
                    )}
                </Table>
                <div className="h-2" />
            </Card>
        </AppLayout>
    );
}

import { Link } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { LAST_GROUP_KEY } from '@/Components/GroupSwitcher';
import Icon from '@/Components/Icon';
import { Badge, Card, CardHeader, EmptyRow, IconTile, Input, Table, Td } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';
import type { InternshipSummary } from '@/types';

type Row = Omit<InternshipSummary, 'work_days' | 'work_days_label'> & { group_id: number; participants_count: number; active_assignments_count: number };

/** Below this many groups a search box is just noise. */
const SEARCH_FROM = 6;

export default function Groups({ internships }: { internships: Row[] }) {
    const [search, setSearch] = useState('');
    const [lastId, setLastId] = useState<number | null>(null);

    useEffect(() => {
        const stored = Number(localStorage.getItem(LAST_GROUP_KEY));
        setLastId(internships.some((row) => row.id === stored) ? stored : null);
    }, [internships]);

    const rows = useMemo(() => {
        const needle = search.trim().toLowerCase();
        const matched = needle === '' ? internships : internships.filter((row) => `${row.group} ${row.program} ${row.course}`.toLowerCase().includes(needle));
        return lastId === null ? matched : [...matched].sort((a, b) => Number(b.id === lastId) - Number(a.id === lastId));
    }, [internships, search, lastId]);

    if (internships.length === 0) {
        return (
            <AppLayout title="Mening guruhlarim">
                <Card className="px-6 py-12 text-center">
                    <IconTile icon="users" tone="info" className="mx-auto mb-4" />
                    <h2 className="text-lg">Hali guruh biriktirilmagan</h2>
                    <p className="mx-auto mt-1 max-w-md text-muted">Admin amaliyot guruhini sizga bog‘lagach, talabalar shu yerda chiqadi.</p>
                </Card>
            </AppLayout>
        );
    }

    return (
        <AppLayout title="Mening guruhlarim">
            <Card>
                <CardHeader
                    title="Mening amaliyot guruhlarim"
                    description="Siz hozir rahbarlik qilayotgan guruhlar. Oxirgi ochgan guruhingiz ro‘yxat boshida turadi."
                    action={
                        internships.length >= SEARCH_FROM ? (
                            <Input className="w-56" placeholder="Guruh yoki yo‘nalish" value={search} onChange={(event) => setSearch(event.target.value)} aria-label="Guruhni qidirish" />
                        ) : undefined
                    }
                />
                <Table head={['Guruh', 'Yo‘nalish / kurs', 'O‘quv yili', 'Muddat', 'Talabalar', 'Faol biriktirish', '']}>
                    {rows.length === 0 ? (
                        <EmptyRow colSpan={7}>Qidiruv bo‘yicha guruh topilmadi.</EmptyRow>
                    ) : (
                        rows.map((row) => (
                            <tr key={row.id}>
                                <Td className="font-medium text-heading">
                                    <Link href={`/my-groups/${row.id}`} className="hover:text-primary-600">
                                        {row.group}
                                    </Link>
                                    {row.id === lastId ? (
                                        <span className="ml-2">
                                            <Badge tone="info">oxirgi</Badge>
                                        </span>
                                    ) : null}
                                </Td>
                                <Td>
                                    {row.program}
                                    <span className="block text-xs text-muted">{row.course}</span>
                                </Td>
                                <Td>{row.year}</Td>
                                <Td className="whitespace-nowrap">
                                    {row.period_start} — {row.period_end}
                                </Td>
                                <Td>{row.participants_count}</Td>
                                <Td>{row.active_assignments_count}</Td>
                                <Td className="text-right">
                                    <Link
                                        href={`/attendance?group=${row.group_id}`}
                                        className="inline-flex items-center gap-1 rounded-md bg-primary-500/15 px-3 py-1.5 text-[0.8125rem] font-medium whitespace-nowrap text-primary-600 hover:bg-primary-500/25 dark:text-primary-300"
                                    >
                                        <Icon name="clock" className="size-4" />
                                        Davomat
                                    </Link>
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

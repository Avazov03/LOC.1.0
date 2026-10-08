import { Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { Badge, Button, EmptyRow, Input, Pagination, Select, StatusBadge, Table, Td } from '@/Components/ui';
import type { Option, Paginated, StudentFilters, StudentRow } from '@/types';

type Props = {
    url: string;
    detailUrl: (id: number) => string;
    students: Paginated<StudentRow>;
    filters: StudentFilters;
    internships: Option[];
    groups?: Option[];
    emptyText: string;
};

export default function StudentList({ url, detailUrl, students, filters, internships, groups, emptyText }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');

    function visit(next: Partial<StudentFilters>) {
        const merged = { ...filters, search: search || null, ...next };
        const query = Object.fromEntries(Object.entries(merged).filter(([, value]) => value !== null && value !== '' && value !== undefined));
        router.get(url, query, { preserveState: true, replace: true });
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        visit({});
    }

    const filtered = Boolean(filters.search || filters.group || filters.internship || filters.status || filters.placement);

    return (
        <>
            <form onSubmit={submit} className="grid gap-3 px-6 pb-4 sm:grid-cols-2 lg:flex lg:flex-wrap" role="search">
                <Input className="lg:max-w-xs" placeholder="F.I.Sh., telefon yoki talaba ID" value={search} onChange={(event) => setSearch(event.target.value)} aria-label="Qidirish" />
                {groups ? (
                    <Select className="lg:max-w-[12rem]" value={filters.group ?? ''} onChange={(event) => visit({ group: event.target.value ? Number(event.target.value) : null })} aria-label="Guruh">
                        <option value="">Barcha guruhlar</option>
                        {groups.map((group) => (
                            <option key={group.id} value={group.id}>
                                {group.name}
                            </option>
                        ))}
                    </Select>
                ) : null}
                <Select className="lg:max-w-[16rem]" value={filters.internship ?? ''} onChange={(event) => visit({ internship: event.target.value ? Number(event.target.value) : null })} aria-label="Amaliyot guruhi">
                    <option value="">Barcha amaliyotlar</option>
                    {internships.map((internship) => (
                        <option key={internship.id} value={internship.id}>
                            {internship.name}
                        </option>
                    ))}
                </Select>
                <Select className="lg:max-w-[12rem]" value={filters.placement ?? ''} onChange={(event) => visit({ placement: event.target.value || null })} aria-label="Biriktirish">
                    <option value="">Biriktirish: barchasi</option>
                    <option value="assigned">Biriktirilgan</option>
                    <option value="unassigned">Biriktirilmagan</option>
                </Select>
                <Select className="lg:max-w-[12rem]" value={filters.status ?? ''} onChange={(event) => visit({ status: event.target.value || null })} aria-label="Holat">
                    <option value="">Barcha holatlar</option>
                    <option value="ACTIVE">Faol</option>
                    <option value="INACTIVE">Nofaol</option>
                    <option value="BLOCKED">Bloklangan</option>
                </Select>
                <div className="flex gap-2">
                    <Button type="submit" variant="tonal" icon="search">
                        Qidirish
                    </Button>
                    {filtered ? (
                        <Button variant="ghost" onClick={() => router.get(url, {}, { replace: true })}>
                            Tozalash
                        </Button>
                    ) : null}
                </div>
            </form>
            <Table head={['F.I.Sh.', 'Telefon', 'Guruh', 'Joriy tashkilot', 'Holat', '']}>
                {students.data.length === 0 ? (
                    <EmptyRow colSpan={6}>{filtered ? 'Filtr bo‘yicha talaba topilmadi.' : emptyText}</EmptyRow>
                ) : (
                    students.data.map((student) => (
                        <tr key={student.id}>
                            <Td className="font-medium text-heading">
                                <Link href={detailUrl(student.id)} className="hover:text-primary-600">
                                    {student.name}
                                </Link>
                                {student.student_code ? <span className="block text-xs font-normal text-muted">ID: {student.student_code}</span> : null}
                            </Td>
                            <Td className="tabular-nums whitespace-nowrap">{student.phone}</Td>
                            <Td>
                                {student.group ?? '—'}
                                {student.program ? (
                                    <span className="block text-xs text-muted">
                                        {student.program}
                                        {student.course ? ` · ${student.course}` : ''}
                                    </span>
                                ) : null}
                            </Td>
                            <Td>
                                {student.assignment ? (
                                    <>
                                        {student.assignment.organization}
                                        <span className="block">
                                            <StatusBadge status={student.assignment.status} />
                                        </span>
                                    </>
                                ) : (
                                    <Badge tone="warning">Biriktirilmagan</Badge>
                                )}
                            </Td>
                            <Td>
                                <StatusBadge status={student.status} />
                            </Td>
                            <Td className="text-right">
                                <Link
                                    href={detailUrl(student.id)}
                                    className="inline-flex items-center rounded-md bg-primary-500/15 px-3 py-1.5 text-[0.8125rem] font-medium text-primary-600 hover:bg-primary-500/25 dark:text-primary-300"
                                >
                                    Ko‘rish
                                </Link>
                            </Td>
                        </tr>
                    ))
                )}
            </Table>
            <Pagination page={students} />
        </>
    );
}

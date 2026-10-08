import { Link } from '@inertiajs/react';
import { Card, CardHeader, IconTile, Table, Td } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';
import type { InternshipSummary } from '@/types';

type Row = InternshipSummary & { participants_count: number; active_assignments_count: number };

export default function Groups({ internships }: { internships: Row[] }) {
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
                <CardHeader title="Mening amaliyot guruhlarim" description="Siz hozir rahbarlik qilayotgan guruhlar." />
                <Table head={['Guruh', 'Yo‘nalish / kurs', 'O‘quv yili', 'Muddat', 'Talabalar', 'Faol biriktirish']}>
                    {internships.map((row) => (
                        <tr key={row.id}>
                            <Td className="font-medium text-heading">
                                <Link href={`/my-groups/${row.id}`} className="hover:text-primary-600">
                                    {row.group}
                                </Link>
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
                        </tr>
                    ))}
                </Table>
                <div className="h-2" />
            </Card>
        </AppLayout>
    );
}

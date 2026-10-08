import { Link, router } from '@inertiajs/react';
import AuditTable from '@/Components/AuditTable';
import Icon from '@/Components/Icon';
import OrganizationMap from '@/Components/OrganizationMap';
import { Button, Card, CardHeader, Dl, EmptyRow, Pagination, StatusBadge, Table, Td } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';
import type { Assignment, AuditEntry, Paginated } from '@/types';

type Organization = {
    id: number;
    name: string;
    type: string;
    address: string;
    contact_name: string;
    contact_phone: string;
    contact_position: string | null;
    website: string | null;
    status: string;
    latitude: number | null;
    longitude: number | null;
    radius_meters: number;
};

export default function OrganizationShow({ organization, assignments, history }: { organization: Organization; assignments: Paginated<Assignment>; history: AuditEntry[] }) {
    function toggle() {
        const next = organization.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE';
        if (next === 'INACTIVE' && !window.confirm('Tashkilot nofaol qilinadi. Mavjud biriktirishlar saqlanadi, yangi biriktirish qabul qilinmaydi.')) {
            return;
        }
        router.patch(`/organizations/${organization.id}/status`, { status: next }, { preserveScroll: true });
    }

    return (
        <AppLayout title={organization.name}>
            <div className="mb-4">
                <Link href="/organizations" className="inline-flex items-center gap-1 text-sm text-muted hover:text-primary-600">
                    <Icon name="chevronLeft" className="size-4" />
                    Tashkilotlar
                </Link>
            </div>
            <div className="space-y-6">
                <Card>
                    <CardHeader
                        title={organization.name}
                        description={organization.type}
                        action={
                            <div className="flex flex-wrap gap-2">
                                <Link
                                    href={`/organizations/${organization.id}/edit`}
                                    className="inline-flex items-center gap-1.5 rounded-md bg-primary-500/15 px-5 py-2 text-[0.9375rem] font-medium text-primary-600 hover:bg-primary-500/25 dark:text-primary-300"
                                >
                                    <Icon name="pencil" className="size-[1.125rem]" />
                                    Tahrirlash
                                </Link>
                                <Button variant="secondary" onClick={toggle}>
                                    {organization.status === 'ACTIVE' ? 'Nofaol qilish' : 'Faollashtirish'}
                                </Button>
                            </div>
                        }
                    />
                    <Dl
                        items={[
                            ['Manzil', organization.address],
                            ['Holat', <StatusBadge key="s" status={organization.status} />],
                            ['Mas’ul', [organization.contact_name, organization.contact_position].filter(Boolean).join(', ')],
                            ['Telefon', organization.contact_phone],
                            ['Sayt', organization.website],
                            ['Koordinata', organization.latitude !== null ? `${organization.latitude}, ${organization.longitude}` : null],
                            ['Radius', `${organization.radius_meters} m`],
                        ]}
                    />
                    <div className="px-6 pb-6">
                        <OrganizationMap latitude={organization.latitude} longitude={organization.longitude} radius={organization.radius_meters} />
                    </div>
                </Card>

                <Card>
                    <CardHeader title="Biriktirilgan talabalar" description="Avval faol va kutilayotganlar, keyin tarix." />
                    <Table head={['Talaba', 'Guruh', 'Rahbar', 'Muddat', 'Holat']}>
                        {assignments.data.length === 0 ? (
                            <EmptyRow colSpan={5}>Bu tashkilotga hali talaba biriktirilmagan.</EmptyRow>
                        ) : (
                            assignments.data.map((assignment) => (
                                <tr key={assignment.id}>
                                    <Td className="font-medium text-heading">
                                        <Link href={`/academic/students/${assignment.student_id}`} className="hover:text-primary-600">
                                            {assignment.student}
                                        </Link>
                                    </Td>
                                    <Td>{assignment.group ?? '—'}</Td>
                                    <Td>{assignment.supervisor ?? '—'}</Td>
                                    <Td className="whitespace-nowrap text-sm">
                                        {assignment.start_at} — {assignment.end_at}
                                    </Td>
                                    <Td>
                                        <StatusBadge status={assignment.status} />
                                    </Td>
                                </tr>
                            ))
                        )}
                    </Table>
                    <Pagination page={assignments} />
                    <div className="h-2" />
                </Card>

                <Card>
                    <CardHeader title="O‘zgarishlar tarixi" description="Joylashuv, radius va holat o‘zgarishlari alohida yoziladi." />
                    <AuditTable logs={history} showEntity={false} />
                    <div className="h-2" />
                </Card>
            </div>
        </AppLayout>
    );
}

import { Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import Icon from '@/Components/Icon';
import { Card, CardHeader, EmptyRow, Input, Pagination, Select, StatusBadge, Table, Td } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';
import type { Paginated } from '@/types';

type Organization = {
    id: number;
    name: string;
    type: string;
    address: string;
    radius_meters: number;
    contact_name: string;
    contact_phone: string;
    status: string;
    active_assignments_count: number;
};

export default function OrganizationsIndex({ organizations, filters }: { organizations: Paginated<Organization>; filters: { search: string | null; status: string | null } }) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState(filters.status ?? '');

    function apply(event?: FormEvent) {
        event?.preventDefault();
        router.get('/organizations', { search: search || undefined, status: status || undefined }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout title="Tashkilotlar">
            <Card>
                <CardHeader
                    title="Tashkilotlar"
                    description="Amaliyot joylari. Joylashuv va radiusni faqat administrator belgilaydi. O‘chirilmaydi, faqat nofaol qilinadi."
                    action={
                        <Link href="/organizations/create" className="inline-flex items-center gap-1.5 rounded-md bg-primary-500 px-5 py-2 text-[0.9375rem] font-medium text-white hover:bg-primary-600">
                            <Icon name="plus" className="size-[1.125rem]" />
                            Yangi tashkilot
                        </Link>
                    }
                />
                <form onSubmit={apply} className="flex flex-wrap gap-3 px-6 pb-4" role="search">
                    <Input className="max-w-xs" placeholder="Nomi yoki manzil" value={search} onChange={(event) => setSearch(event.target.value)} aria-label="Qidirish" />
                    <Select
                        className="max-w-[12rem]"
                        value={status}
                        onChange={(event) => {
                            setStatus(event.target.value);
                            router.get('/organizations', { search: search || undefined, status: event.target.value || undefined }, { preserveState: true, replace: true });
                        }}
                        aria-label="Holat"
                    >
                        <option value="">Barcha holatlar</option>
                        <option value="ACTIVE">Faol</option>
                        <option value="INACTIVE">Nofaol</option>
                    </Select>
                </form>
                <Table head={['Nomi', 'Manzil', 'Radius', 'Mas’ul', 'Faol talabalar', 'Holat', '']}>
                    {organizations.data.length === 0 ? (
                        <EmptyRow colSpan={7}>Tashkilot topilmadi.</EmptyRow>
                    ) : (
                        organizations.data.map((organization) => (
                            <tr key={organization.id}>
                                <Td className="font-medium text-heading">
                                    <Link href={`/organizations/${organization.id}`} className="hover:text-primary-600">
                                        {organization.name}
                                    </Link>
                                    <span className="block text-xs font-normal text-muted">{organization.type}</span>
                                </Td>
                                <Td>{organization.address}</Td>
                                <Td>{organization.radius_meters} m</Td>
                                <Td>
                                    {organization.contact_name}
                                    <span className="block text-xs text-muted">{organization.contact_phone}</span>
                                </Td>
                                <Td>{organization.active_assignments_count}</Td>
                                <Td>
                                    <StatusBadge status={organization.status} />
                                </Td>
                                <Td className="text-right">
                                    <Link
                                        href={`/organizations/${organization.id}/edit`}
                                        className="inline-flex items-center gap-1.5 rounded-md bg-primary-500/15 px-3 py-1.5 text-[0.8125rem] font-medium text-primary-600 hover:bg-primary-500/25 dark:text-primary-300"
                                    >
                                        <Icon name="pencil" className="size-4" />
                                        Tahrirlash
                                    </Link>
                                </Td>
                            </tr>
                        ))
                    )}
                </Table>
                <Pagination page={organizations} />
            </Card>
        </AppLayout>
    );
}

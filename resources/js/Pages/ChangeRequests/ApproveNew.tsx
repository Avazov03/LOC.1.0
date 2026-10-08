import { Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import OrganizationFields, { type OrganizationData } from '@/Components/OrganizationFields';
import { Button, Card, CardHeader, Dl } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';
import type { ChangeRequest } from '@/types';

export default function ApproveNew({ request }: { request: ChangeRequest }) {
    const proposed = request.requested_data ?? {};
    const form = useForm<OrganizationData>({
        name: proposed.name ?? '',
        type: proposed.type ?? '',
        address: proposed.address ?? '',
        contact_name: proposed.contact_name ?? '',
        contact_phone: proposed.contact_phone ?? '',
        contact_position: '',
        website: proposed.website ?? '',
        latitude: null,
        longitude: null,
        radius_meters: 100,
        status: 'ACTIVE',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(`/change-requests/${request.id}/approve-new`);
    }

    const pending = request.status === 'PENDING' && request.type === 'NEW_ORGANIZATION';

    return (
        <AppLayout title="Yangi tashkilotni tasdiqlash">
            <div className="space-y-6">
                <Card>
                    <CardHeader title={`So‘rov #${request.id}: ${request.student}`} description={request.reason} />
                    <Dl
                        items={[
                            ['Guruh', request.group],
                            ['Joriy tashkilot', request.current_organization],
                            ['Taklif qilingan nom', proposed.name],
                            ['Holat', request.status],
                        ]}
                    />
                </Card>
                <Card>
                    <CardHeader
                        title="Tashkilotni yaratish"
                        description="Talaba yuborgan ma’lumot faqat taklif. Manzilni tekshiring va nuqtani xaritada o‘zingiz belgilang. Saqlaganda tashkilot faol bo‘ladi, talaba unga biriktiriladi va so‘rov tasdiqlanadi — barchasi bitta tranzaksiyada."
                    />
                    {pending ? (
                        <form onSubmit={submit} noValidate className="px-6 pb-6">
                            <OrganizationFields data={form.data} errors={form.errors} setData={form.setData} />
                            <div className="mt-6 flex justify-end gap-3">
                                <Link href="/change-requests" className="inline-flex items-center rounded-md bg-secondary/15 px-5 py-2 text-[0.9375rem] font-medium text-secondary hover:bg-secondary/25">
                                    Orqaga
                                </Link>
                                <Button type="submit" disabled={form.processing}>
                                    Yaratish va tasdiqlash
                                </Button>
                            </div>
                        </form>
                    ) : (
                        <p className="px-6 pb-6 text-muted">Bu so‘rov allaqachon ko‘rib chiqilgan.</p>
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}

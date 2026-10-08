import { Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import OrganizationFields, { type OrganizationData } from '@/Components/OrganizationFields';
import { Button, Card, CardHeader } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';

type Existing = OrganizationData & { id: number };

export default function OrganizationForm({ organization }: { organization: Existing | null }) {
    const form = useForm<OrganizationData>({
        name: organization?.name ?? '',
        type: organization?.type ?? '',
        address: organization?.address ?? '',
        contact_name: organization?.contact_name ?? '',
        contact_phone: organization?.contact_phone ?? '',
        contact_position: organization?.contact_position ?? '',
        website: organization?.website ?? '',
        latitude: organization?.latitude ?? null,
        longitude: organization?.longitude ?? null,
        radius_meters: organization?.radius_meters ?? 100,
        status: organization?.status ?? 'ACTIVE',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        if (organization) {
            form.put(`/organizations/${organization.id}`);
            return;
        }
        form.post('/organizations');
    }

    const title = organization ? 'Tashkilotni tahrirlash' : 'Yangi tashkilot';

    return (
        <AppLayout title={title}>
            <Card>
                <CardHeader
                    title={title}
                    description="Xaritada tashkilot markazini bosing yoki markerni suring. Joylashuv yoki radius o‘zgarsa, avvalgi davomat yozuvlari o‘z holicha qoladi."
                />
                <form onSubmit={submit} noValidate className="px-6 pb-6">
                    <OrganizationFields data={form.data} errors={form.errors} setData={form.setData} showStatus={organization !== null} />
                    <div className="mt-6 flex justify-end gap-3">
                        <Link href={organization ? `/organizations/${organization.id}` : '/organizations'} className="inline-flex items-center rounded-md bg-secondary/15 px-5 py-2 text-[0.9375rem] font-medium text-secondary hover:bg-secondary/25">
                            Bekor
                        </Link>
                        <Button type="submit" disabled={form.processing}>
                            Saqlash
                        </Button>
                    </div>
                </form>
            </Card>
        </AppLayout>
    );
}

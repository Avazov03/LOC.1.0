import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AuditTable from '@/Components/AuditTable';
import Icon from '@/Components/Icon';
import { Badge, Button, Card, CardHeader, Dl, EmptyRow, StatusBadge, Table, Td } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';
import type { AuditEntry, SharedProps } from '@/types';

type Supervisor = {
    id: number;
    name: string;
    login: string;
    email: string | null;
    phone: string;
    position: string;
    status: string;
    last_login_at: string | null;
    open_students: number;
    telegram_linked: boolean;
    telegram_linked_at: string | null;
};

type Period = {
    id: number;
    internship_id: number;
    group: string;
    program: string;
    year: string;
    period_start: string;
    period_end: string;
    starts_on: string;
    ends_on: string | null;
    participants_count: number;
};

export default function SupervisorShow({ supervisor, periods, history }: { supervisor: Supervisor; periods: Period[]; history: AuditEntry[] }) {
    const current = periods.filter((period) => period.ends_on === null);
    const { flash } = usePage<SharedProps>().props;
    const [copied, setCopied] = useState(false);
    const link = flash.telegram_link ?? null;
    const shown = link && !link.startsWith('http') ? `/start ${link}` : link;

    async function copy(text: string) {
        await navigator.clipboard?.writeText(text);
        setCopied(true);
    }

    function toggle() {
        const next = supervisor.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE';
        if (next === 'INACTIVE' && !window.confirm('Rahbar nofaol qilinadi: tizimga kira olmaydi va talabalarni ko‘rmaydi. Guruhlarini boshqa rahbarga almashtirishni unutmang.')) {
            return;
        }
        router.patch(`/supervisors/${supervisor.id}/status`, { status: next }, { preserveScroll: true });
    }

    return (
        <AppLayout title={supervisor.name}>
            <div className="mb-4">
                <Link href="/supervisors" className="inline-flex items-center gap-1 text-sm text-muted hover:text-primary-600">
                    <Icon name="chevronLeft" className="size-4" />
                    Rahbarlar
                </Link>
            </div>
            <div className="space-y-6">
                <Card>
                    <CardHeader
                        title={supervisor.name}
                        description={supervisor.position}
                        action={
                            <Button variant="secondary" onClick={toggle}>
                                {supervisor.status === 'ACTIVE' ? 'Nofaol qilish' : 'Faollashtirish'}
                            </Button>
                        }
                    />
                    <Dl
                        items={[
                            ['Login', supervisor.login],
                            ['Telefon', supervisor.phone],
                            ['Email', supervisor.email],
                            ['Holat', <StatusBadge key="s" status={supervisor.status} />],
                            ['Joriy guruhlar', current.length],
                            ['Joriy talabalar', supervisor.open_students],
                            ['Oxirgi kirish', supervisor.last_login_at ?? 'Hali kirmagan'],
                            [
                                'Telegram',
                                <span key="tg" className="inline-flex flex-wrap items-center gap-2">
                                    {supervisor.telegram_linked ? <Badge tone="success">Ulangan · {supervisor.telegram_linked_at}</Badge> : <Badge tone="secondary">Ulanmagan</Badge>}
                                    <Button size="sm" variant="tonal" icon="link" onClick={() => { setCopied(false); router.post(`/supervisors/${supervisor.id}/telegram`, {}, { preserveScroll: true }); }}>
                                        Havola yaratish
                                    </Button>
                                    {supervisor.telegram_linked ? (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => window.confirm('Rahbarning Telegram ulanishini uzasizmi?') && router.delete(`/supervisors/${supervisor.id}/telegram`, { preserveScroll: true })}
                                        >
                                            Uzish
                                        </Button>
                                    ) : null}
                                </span>,
                            ],
                        ]}
                    />
                    {shown ? (
                        <div className="mx-6 mb-6 rounded-lg border border-primary-500/40 bg-primary-500/10 p-4" role="status">
                            <p className="text-sm font-medium text-heading">Rahbarga yuboring. Havola 24 soat va faqat bir marta ishlaydi, faqat hozir ko‘rsatiladi:</p>
                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                <code className="min-w-0 flex-1 rounded bg-panel px-3 py-2 text-sm break-all text-heading">{shown}</code>
                                <Button size="sm" icon={copied ? 'check' : 'copy'} onClick={() => copy(shown)}>
                                    {copied ? 'Nusxalandi' : 'Nusxalash'}
                                </Button>
                            </div>
                        </div>
                    ) : null}
                </Card>

                <Card>
                    <CardHeader title="Rahbarlik tarixi" description="Joriy va avvalgi amaliyot guruhlari. Almashtirishda tarix saqlanadi; rahbarni almashtirish amaliyot guruhi sahifasida." />
                    <Table head={['Guruh', 'O‘quv yili', 'Amaliyot muddati', 'Rahbarlik davri', 'Talabalar', '']}>
                        {periods.length === 0 ? (
                            <EmptyRow colSpan={6}>Rahbar hali amaliyot guruhiga biriktirilmagan.</EmptyRow>
                        ) : (
                            periods.map((period) => (
                                <tr key={period.id}>
                                    <Td className="font-medium text-heading">
                                        {period.group}
                                        <span className="block text-xs font-normal text-muted">{period.program}</span>
                                    </Td>
                                    <Td>{period.year}</Td>
                                    <Td className="whitespace-nowrap text-sm">
                                        {period.period_start} — {period.period_end}
                                    </Td>
                                    <Td className="whitespace-nowrap text-sm">
                                        {period.starts_on} — {period.ends_on ?? <Badge tone="success">hozirgacha</Badge>}
                                    </Td>
                                    <Td>{period.participants_count}</Td>
                                    <Td className="text-right">
                                        <Link
                                            href={`/internships/${period.internship_id}`}
                                            className="inline-flex items-center rounded-md bg-primary-500/15 px-3 py-1.5 text-[0.8125rem] font-medium text-primary-600 hover:bg-primary-500/25 dark:text-primary-300"
                                        >
                                            Guruhni ochish
                                        </Link>
                                    </Td>
                                </tr>
                            ))
                        )}
                    </Table>
                    <div className="h-2" />
                </Card>

                <Card>
                    <CardHeader title="O‘zgarishlar tarixi" />
                    <AuditTable logs={history} showEntity={false} />
                    <div className="h-2" />
                </Card>
            </div>
        </AppLayout>
    );
}

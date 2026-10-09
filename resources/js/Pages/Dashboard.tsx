import { Link, usePage } from '@inertiajs/react';
import { DayMarkControls, DayStatusBadge } from '@/Components/AttendanceUi';
import type { IconName } from '@/Components/Icon';
import { Card, IconTile, type Tone } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';
import type { DayStatus, SharedProps } from '@/types';

type Stat = { label: string; value: number; icon: IconName; tone: Tone; href: string };

const number = new Intl.NumberFormat('uz-UZ');

type AttendanceTotals = Record<DayStatus | 'EXPECTED', number>;
type Unmarked = { total: number; rows: Array<{ student_id: number; name: string; status: DayStatus }> };

const attendanceCards: Array<{ key: keyof AttendanceTotals; label: string; icon: IconName; tone: Tone }> = [
    { key: 'PRESENT', label: 'Keldi', icon: 'check', tone: 'success' },
    { key: 'INCOMPLETE', label: 'Yakunlanmagan', icon: 'clock', tone: 'warning' },
    { key: 'LOCATION_REJECTED', label: 'Joylashuv rad etildi', icon: 'mapPin', tone: 'danger' },
    { key: 'EXCUSED', label: 'Sababli', icon: 'alert', tone: 'info' },
    { key: 'ABSENT', label: 'Kelmadi', icon: 'ban', tone: 'secondary' },
];

export default function Dashboard({
    stats,
    timezone,
    attendance,
    unmarked,
    reminderTime,
    today,
}: {
    stats: Stat[];
    timezone: string;
    attendance: AttendanceTotals;
    unmarked: Unmarked;
    reminderTime: string;
    today: string;
}) {
    const { auth } = usePage<SharedProps>().props;
    const admin = auth.user?.role === 'ADMIN';
    const empty = !admin && stats[0]?.value === 0;

    return (
        <AppLayout title="Boshqaruv">
            <div className="mb-6">
                <h2 className="text-2xl">Xush kelibsiz, {auth.user?.name}</h2>
                <p className="mt-1 text-muted">
                    {admin
                        ? 'Universitet bo‘yicha talabalar, amaliyot, biriktirishlar va so‘rovlar holati.'
                        : 'Sizga biriktirilgan amaliyot guruhlari va talabalar holati.'}{' '}
                    <span className="whitespace-nowrap">Vaqt zonasi: {timezone}.</span>
                </p>
            </div>

            <div className="grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
                {stats.map((stat) => (
                    <Link key={stat.label} href={stat.href} className="group rounded-lg focus-visible:outline-2">
                        <Card className="h-full p-6 transition group-hover:-translate-y-0.5">
                            <div className="flex items-start justify-between gap-4">
                                <div>
                                    <p className="text-sm text-muted">{stat.label}</p>
                                    <p className="mt-1 text-2xl font-semibold text-heading tabular-nums">{number.format(stat.value)}</p>
                                </div>
                                <IconTile icon={stat.icon} tone={stat.tone} />
                            </div>
                        </Card>
                    </Link>
                ))}
            </div>

            <Card className="mt-6">
                <div className="flex flex-wrap items-center justify-between gap-3 px-6 pt-6">
                    <div>
                        <h3 className="text-lg">Bugungi davomat</h3>
                        <p className="text-sm text-muted">
                            {today} · kutilgan talabalar: <span className="tabular-nums">{number.format(attendance.EXPECTED)}</span>
                        </p>
                    </div>
                    <Link href="/attendance" className="text-sm font-medium text-primary-600 hover:underline dark:text-primary-300">
                        Batafsil →
                    </Link>
                </div>
                <div className="grid gap-4 p-6 sm:grid-cols-3 xl:grid-cols-5">
                    {attendanceCards.map((card) => (
                        <Link
                            key={card.key}
                            href={`/attendance?status=${card.key}`}
                            className="flex items-center gap-3 rounded-md border border-line p-4 transition hover:border-primary-500"
                        >
                            <IconTile icon={card.icon} tone={card.tone} />
                            <div>
                                <p className="text-xl font-semibold text-heading tabular-nums">{number.format(attendance[card.key])}</p>
                                <p className="text-xs text-muted">{card.label}</p>
                            </div>
                        </Link>
                    ))}
                </div>
            </Card>

            {unmarked.total > 0 && (
                <Card className="mt-6">
                    <div className="flex flex-wrap items-center justify-between gap-3 px-6 pt-6">
                        <div>
                            <h3 className="text-lg">Bugun belgilanmaganlar · {number.format(unmarked.total)}</h3>
                            <p className="text-sm text-muted">
                                Bugun amaliyot kuni, lekin kelish qayd etilmagan. Talaba boshqa joyda ishlagan bo‘lsa «Keldi», uzrli sabab bo‘lsa «Sababli» deb belgilang.
                                {admin ? '' : ` Soat ${reminderTime} da shu ro‘yxat Telegram botingizga ham yuboriladi.`}
                            </p>
                        </div>
                        {unmarked.total > unmarked.rows.length ? (
                            <Link href={`/attendance?date=${today}&status=ABSENT`} className="text-sm font-medium text-primary-600 hover:underline dark:text-primary-300">
                                Hammasi →
                            </Link>
                        ) : null}
                    </div>
                    <ul className="divide-y divide-line px-6 py-3">
                        {unmarked.rows.map((row) => (
                            <li key={row.student_id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                <div className="flex items-center gap-3">
                                    <Link href={`/attendance/students/${row.student_id}`} className="font-medium text-heading hover:text-primary-600">
                                        {row.name}
                                    </Link>
                                    <DayStatusBadge status={row.status} />
                                </div>
                                <DayMarkControls studentId={row.student_id} date={today} status={row.status} mark={null} canMark />
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            {empty && (
                <Card className="mt-6 px-6 py-12 text-center">
                    <IconTile icon="users" tone="primary" className="mx-auto mb-4" />
                    <h3 className="text-lg">Guruhlar hali biriktirilmagan</h3>
                    <p className="mx-auto mt-1 max-w-md text-muted">Admin amaliyot guruhini sizga bog‘lagach, talabalar shu yerda chiqadi.</p>
                </Card>
            )}
        </AppLayout>
    );
}

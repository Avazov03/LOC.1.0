import { Link } from '@inertiajs/react';
import { useEffect } from 'react';
import GroupSwitcher, { LAST_GROUP_KEY } from '@/Components/GroupSwitcher';
import Icon from '@/Components/Icon';
import ParticipantAssign from '@/Components/ParticipantAssign';
import { Card, CardHeader, Dl } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';
import type { InternshipSummary, OrganizationOption, Participant } from '@/types';

type GroupLink = { id: number; group: string; participants_count: number };

export default function GroupShow({
    internship,
    participants,
    organizations,
    groups,
}: {
    internship: InternshipSummary & { group_id: number };
    participants: Participant[];
    organizations: OrganizationOption[];
    groups: GroupLink[];
}) {
    useEffect(() => {
        localStorage.setItem(LAST_GROUP_KEY, String(internship.id));
    }, [internship.id]);

    return (
        <AppLayout title={`Guruh: ${internship.group}`}>
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <Link href="/my-groups" className="inline-flex items-center gap-1 text-sm text-muted hover:text-primary-600">
                    <Icon name="chevronLeft" className="size-4" />
                    Mening guruhlarim
                </Link>
                <Link
                    href={`/attendance?group=${internship.group_id}`}
                    className="inline-flex items-center gap-1.5 rounded-md bg-primary-500/15 px-3 py-1.5 text-sm font-medium text-primary-600 hover:bg-primary-500/25 dark:text-primary-300"
                >
                    <Icon name="clock" className="size-4" />
                    Bugungi davomat
                </Link>
            </div>
            <div className="mb-4">
                <GroupSwitcher
                    active={String(internship.id)}
                    items={groups.map((row) => ({ key: String(row.id), label: row.group, count: row.participants_count, href: `/my-groups/${row.id}` }))}
                />
            </div>
            <div className="space-y-6">
                <Card>
                    <CardHeader title={`${internship.group} — ${internship.course}`} description={internship.program} />
                    <Dl
                        items={[
                            ['O‘quv yili', internship.year],
                            ['Muddat', `${internship.period_start} — ${internship.period_end}`],
                            ['Amaliyot kunlari', internship.work_days_label],
                            ['Talabalar', participants.length],
                            ['Biriktirilgan', participants.filter((participant) => participant.assignment !== null).length],
                        ]}
                    />
                </Card>
                <ParticipantAssign
                    internshipId={internship.id}
                    groupWorkDays={internship.work_days}
                    groupWorkDaysLabel={internship.work_days_label}
                    periodStart={internship.period_start}
                    periodEnd={internship.period_end}
                    participants={participants}
                    organizations={organizations}
                    studentHref={(id) => `/students/${id}`}
                />
            </div>
        </AppLayout>
    );
}

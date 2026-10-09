import { Link } from '@inertiajs/react';
import Icon from '@/Components/Icon';
import ParticipantAssign from '@/Components/ParticipantAssign';
import { Card, CardHeader, Dl } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';
import type { InternshipSummary, OrganizationOption, Participant } from '@/types';

export default function GroupShow({ internship, participants, organizations }: { internship: InternshipSummary; participants: Participant[]; organizations: OrganizationOption[] }) {
    return (
        <AppLayout title={`Guruh: ${internship.group}`}>
            <div className="mb-4">
                <Link href="/my-groups" className="inline-flex items-center gap-1 text-sm text-muted hover:text-primary-600">
                    <Icon name="chevronLeft" className="size-4" />
                    Mening guruhlarim
                </Link>
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

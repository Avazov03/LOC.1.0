/// <reference types="vite/client" />

import type { IconName } from '@/Components/Icon';

export type Role = 'ADMIN' | 'SUPERVISOR' | 'STUDENT';

export type NavEntry = {
    header?: string;
    label?: string;
    href?: string;
    icon?: IconName;
};

export type SharedProps = {
    auth: {
        user: { name: string; login: string | null; role: Role; university: string | null } | null;
        impersonating: boolean;
    };
    navigation: NavEntry[];
    flash: {
        success?: string | null;
        error?: string | null;
        invite_link?: string | null;
        telegram_link?: string | null;
        assignment_results?: AssignmentResult[] | null;
    };
    appName: string;
};

export type Option = { id: number; name: string };

export type OrganizationOption = { id: number; name: string; address: string };

export type AssignmentResult = { student_id: number; assignment_id: number | null; status: string | null; error: string | null };

export type Participant = {
    id: number;
    name: string;
    phone: string;
    student_code: string | null;
    status: string;
    joined_at: string | null;
    work_days: number[] | null;
    work_days_label: string | null;
    assignment: { id: number; organization: string | null; status: string; start_at: string | null; end_at: string | null } | null;
};

export type Assignment = {
    id: number;
    student_id: number;
    student: string | null;
    organization: string | null;
    organization_id: number;
    group: string | null;
    supervisor: string | null;
    status: string;
    start_at: string | null;
    end_at: string | null;
    ended_at: string | null;
    cancel_reason: string | null;
};

export type ChangeRequest = {
    id: number;
    student_id: number;
    student: string | null;
    group: string | null;
    type: 'EXISTING_ORGANIZATION' | 'NEW_ORGANIZATION';
    status: string;
    current_organization: string | null;
    requested_organization: string | null;
    requested_data: Record<string, string> | null;
    reason: string;
    initiator: string | null;
    initiator_role: Role | null;
    reviewer: string | null;
    review_note: string | null;
    created_at: string | null;
    reviewed_at: string | null;
};

export type AuditEntry = {
    id: number;
    action: string;
    entity_type: string;
    entity_id: number;
    actor: string;
    before: Record<string, unknown> | null;
    after: Record<string, unknown> | null;
    reason: string | null;
    created_at: string | null;
};

export type StudentRow = {
    id: number;
    name: string;
    phone: string;
    student_code: string | null;
    group: string | null;
    program: string | null;
    course: string | null;
    status: string;
    assignment: { organization: string | null; status: string } | null;
};

export type StudentFilters = { search: string | null; group?: number | null; internship: number | null; status: string | null; placement: string | null };

export type StudentDetail = {
    id: number;
    name: string;
    first_name: string;
    last_name: string;
    phone: string;
    student_code: string | null;
    status: string;
    telegram_user_id: string | null;
    telegram_linked: boolean;
    registered_at: string | null;
    group: string | null;
    course: string | null;
    program: string | null;
    faculty: string | null;
    academic_year: string | null;
    memberships: { id: number; group: string | null; academic_year: string | null; status: string; joined_at: string | null; ended_at: string | null }[];
    participations: {
        internship_id: number;
        group: string | null;
        academic_year: string | null;
        period_start: string | null;
        period_end: string | null;
        supervisor: string | null;
        joined_at: string | null;
    }[];
};

export type InternshipSummary = {
    id: number;
    group: string;
    program: string;
    course: string;
    year: string;
    period_start: string;
    period_end: string;
    work_days: number[];
    work_days_label: string;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type DayStatus = 'PRESENT' | 'INCOMPLETE' | 'LOCATION_REJECTED' | 'EXCUSED' | 'ABSENT';

export type DayMark = { id: number; kind: 'PRESENT' | 'EXCUSED'; note: string | null };

export type AttendanceFilterValues = {
    search: string | null;
    faculty: number | null;
    program: number | null;
    course: number | null;
    group: number | null;
    internship: number | null;
    organization: number | null;
    supervisor: number | null;
    status: DayStatus | null;
    date?: string;
    from?: string;
    to?: string;
};

export type AttendanceFilterOptions = {
    groups: Option[];
    internships: Option[];
    organizations: Option[];
    faculties?: Option[];
    programs?: Option[];
    courses?: Option[];
    supervisors?: Option[];
};

export type StatusOption = { value: DayStatus; label: string };

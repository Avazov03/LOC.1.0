import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import Modal, { ModalBody, ModalFooter } from '@/Components/Modal';
import { Badge, Button, EmptyRow, Field, StatusBadge, Table, Td, Textarea } from '@/Components/ui';
import type { ChangeRequest, Role } from '@/types';

const dataLabels: Record<string, string> = {
    name: 'Nomi',
    type: 'Turi',
    address: 'Manzil',
    contact_name: 'Mas’ul',
    contact_phone: 'Telefon',
    website: 'Sayt',
};

/**
 * Actions shown here mirror the server rules (A24); the server decides again on every click.
 */
export default function ChangeRequestTable({ requests, role, showStudent = true }: { requests: ChangeRequest[]; role: Role; showStudent?: boolean }) {
    const [rejecting, setRejecting] = useState<ChangeRequest | null>(null);
    const [note, setNote] = useState('');

    function approve(request: ChangeRequest) {
        if (window.confirm('Tasdiqlaysizmi? Joriy biriktirish yakunlanadi va yangi tashkilotga biriktiriladi.')) {
            router.post(`/change-requests/${request.id}/approve`, {}, { preserveScroll: true });
        }
    }

    function cancel(request: ChangeRequest) {
        if (window.confirm('So‘rovni bekor qilasizmi?')) {
            router.post(`/change-requests/${request.id}/cancel`, {}, { preserveScroll: true });
        }
    }

    function reject() {
        if (!rejecting) {
            return;
        }
        router.post(`/change-requests/${rejecting.id}/reject`, { note }, { preserveScroll: true, onSuccess: () => setRejecting(null) });
    }

    const head = [...(showStudent ? ['Talaba'] : []), 'So‘rov', 'Sabab', 'Kim ochgan', 'Holat', ''];

    return (
        <>
            <Table head={head}>
                {requests.length === 0 ? (
                    <EmptyRow colSpan={head.length}>So‘rov yo‘q.</EmptyRow>
                ) : (
                    requests.map((request) => {
                        const pending = request.status === 'PENDING';
                        const existing = request.type === 'EXISTING_ORGANIZATION';

                        return (
                            <tr key={request.id}>
                                {showStudent ? (
                                    <Td className="font-medium text-heading">
                                        {role === 'SUPERVISOR' ? (
                                            <Link href={`/students/${request.student_id}`} className="hover:text-primary-600">
                                                {request.student}
                                            </Link>
                                        ) : (
                                            request.student
                                        )}
                                        <span className="block text-xs font-normal text-muted">{request.group}</span>
                                    </Td>
                                ) : null}
                                <Td>
                                    <Badge tone={existing ? 'info' : 'warning'}>{existing ? 'Mavjud tashkilot' : 'Yangi tashkilot'}</Badge>
                                    <p className="mt-1 text-sm">
                                        {request.current_organization ?? '—'} → <span className="font-medium text-heading">{request.requested_organization ?? request.requested_data?.name}</span>
                                    </p>
                                    {!existing && request.requested_data ? (
                                        <dl className="mt-1 text-xs text-muted">
                                            {Object.entries(request.requested_data)
                                                .filter(([key]) => key !== 'name')
                                                .map(([key, value]) => (
                                                    <div key={key}>
                                                        {dataLabels[key] ?? key}: {value}
                                                    </div>
                                                ))}
                                        </dl>
                                    ) : null}
                                </Td>
                                <Td className="max-w-xs text-sm">{request.reason}</Td>
                                <Td className="text-sm">
                                    {request.initiator}
                                    <span className="block text-xs text-muted">{request.created_at}</span>
                                </Td>
                                <Td>
                                    <StatusBadge status={request.status} />
                                    {request.reviewer ? (
                                        <span className="block text-xs text-muted">
                                            {request.reviewer}, {request.reviewed_at}
                                        </span>
                                    ) : null}
                                    {request.review_note ? <span className="block text-xs text-muted">“{request.review_note}”</span> : null}
                                </Td>
                                <Td className="text-right whitespace-nowrap">
                                    {pending && existing ? (
                                        <Button size="sm" onClick={() => approve(request)}>
                                            Tasdiqlash
                                        </Button>
                                    ) : null}
                                    {pending && !existing && role === 'ADMIN' ? (
                                        <Link
                                            href={`/change-requests/${request.id}/approve-new`}
                                            className="inline-flex items-center rounded-md bg-primary-500 px-3 py-1.5 text-[0.8125rem] font-medium text-white hover:bg-primary-600"
                                        >
                                            Tashkilot yaratib tasdiqlash
                                        </Link>
                                    ) : null}
                                    {pending && !existing && role === 'SUPERVISOR' ? <span className="text-xs text-muted">Administrator ko‘rib chiqadi</span> : null}{' '}
                                    {pending && (existing || role === 'ADMIN') ? (
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            onClick={() => {
                                                setNote('');
                                                setRejecting(request);
                                            }}
                                        >
                                            Rad etish
                                        </Button>
                                    ) : null}{' '}
                                    {pending && role === 'ADMIN' ? (
                                        <Button size="sm" variant="ghost" onClick={() => cancel(request)}>
                                            Bekor
                                        </Button>
                                    ) : null}
                                </Td>
                            </tr>
                        );
                    })
                )}
            </Table>

            <Modal title="So‘rovni rad etish" open={rejecting !== null} onClose={() => setRejecting(null)}>
                <ModalBody>
                    <Field label="Izoh (ixtiyoriy)" htmlFor="note">
                        <Textarea id="note" value={note} onChange={(event) => setNote(event.target.value)} />
                    </Field>
                </ModalBody>
                <ModalFooter>
                    <Button variant="secondary" onClick={() => setRejecting(null)}>
                        Yopish
                    </Button>
                    <Button onClick={reject}>Rad etish</Button>
                </ModalFooter>
            </Modal>
        </>
    );
}

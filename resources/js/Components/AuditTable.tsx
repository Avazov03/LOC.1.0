import { Fragment, useState } from 'react';
import { Badge, Button, EmptyRow, Table, Td } from '@/Components/ui';
import type { AuditEntry } from '@/types';

export default function AuditTable({ logs, showEntity = true }: { logs: AuditEntry[]; showEntity?: boolean }) {
    const [expanded, setExpanded] = useState<number | null>(null);
    const columns = showEntity ? 5 : 4;

    return (
        <Table head={showEntity ? ['Vaqt', 'Kim', 'Amal', 'Obyekt', ''] : ['Vaqt', 'Kim', 'Amal', '']}>
            {logs.length === 0 ? (
                <EmptyRow colSpan={columns}>Yozuv yo‘q.</EmptyRow>
            ) : (
                logs.map((log) => (
                    <Fragment key={log.id}>
                        <tr>
                            <Td className="whitespace-nowrap text-sm">{log.created_at}</Td>
                            <Td>{log.actor}</Td>
                            <Td>
                                <Badge tone="primary">{log.action}</Badge>
                                {log.reason ? <span className="block text-xs text-muted">{log.reason}</span> : null}
                            </Td>
                            {showEntity ? (
                                <Td className="text-sm">
                                    {log.entity_type} #{log.entity_id}
                                </Td>
                            ) : null}
                            <Td className="text-right">
                                {log.before || log.after ? (
                                    <Button size="sm" variant="ghost" icon="eye" onClick={() => setExpanded(expanded === log.id ? null : log.id)}>
                                        {expanded === log.id ? 'Yashirish' : 'Batafsil'}
                                    </Button>
                                ) : null}
                            </Td>
                        </tr>
                        {expanded === log.id ? (
                            <tr>
                                <td colSpan={columns} className="bg-heading/[0.03] px-6 py-3">
                                    <div className="grid gap-4 text-xs md:grid-cols-2">
                                        <div>
                                            <p className="mb-1 font-medium text-heading">Oldin</p>
                                            <pre className="overflow-x-auto whitespace-pre-wrap text-body">{JSON.stringify(log.before, null, 2) ?? '—'}</pre>
                                        </div>
                                        <div>
                                            <p className="mb-1 font-medium text-heading">Keyin</p>
                                            <pre className="overflow-x-auto whitespace-pre-wrap text-body">{JSON.stringify(log.after, null, 2) ?? '—'}</pre>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        ) : null}
                    </Fragment>
                ))
            )}
        </Table>
    );
}

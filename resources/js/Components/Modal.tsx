import { ReactNode, useEffect, useId } from 'react';
import Icon from '@/Components/Icon';

export default function Modal({
    title,
    open,
    onClose,
    children,
}: {
    title: string;
    open: boolean;
    onClose: () => void;
    children: ReactNode;
}) {
    const titleId = useId();

    useEffect(() => {
        if (!open) {
            return;
        }

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };
        document.addEventListener('keydown', onKey);
        document.body.style.overflow = 'hidden';

        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = '';
        };
    }, [open, onClose]);

    if (!open) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 sm:items-center">
            <div className="fixed inset-0 bg-[#22303e]/50" onClick={onClose} aria-hidden="true" />
            <div role="dialog" aria-modal="true" aria-labelledby={titleId} className="relative w-full max-w-lg rounded-lg bg-panel shadow-xl">
                <div className="flex items-center justify-between px-6 pt-6 pb-2">
                    <h2 id={titleId} className="text-lg">
                        {title}
                    </h2>
                    <button type="button" onClick={onClose} className="rounded-md p-1.5 text-muted transition hover:bg-heading/5 hover:text-heading" aria-label="Yopish">
                        <Icon name="close" className="size-5" />
                    </button>
                </div>
                {children}
            </div>
        </div>
    );
}

export function ModalBody({ children }: { children: ReactNode }) {
    return <div className="space-y-5 px-6 py-4">{children}</div>;
}

export function ModalFooter({ children }: { children: ReactNode }) {
    return <div className="flex justify-end gap-3 px-6 pt-2 pb-6">{children}</div>;
}

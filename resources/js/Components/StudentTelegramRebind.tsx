import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Badge, Button } from '@/Components/ui';
import type { SharedProps } from '@/types';

export function StudentTelegramStatus({ studentId, linked, active }: { studentId: number; linked: boolean; active: boolean }) {
    function create() {
        if (
            window.confirm(
                'Talaba yangi Telegram hisobiga o‘tkaziladi. Havolani faqat talabaning o‘ziga bering: havola ochilgach eski Telegram hisobi davomat qila olmaydi. Davomat tarixi va amaliyot joyi saqlanadi. Davom etasizmi?',
            )
        ) {
            router.post(`/students/${studentId}/telegram-rebind`, {}, { preserveScroll: true });
        }
    }

    return (
        <span className="inline-flex flex-wrap items-center gap-2">
            {linked ? <Badge tone="success">Ulangan</Badge> : <Badge tone="secondary">Ulanmagan</Badge>}
            {active ? (
                <Button size="sm" variant="tonal" icon="link" onClick={create}>
                    Telegram’ni qayta bog‘lash
                </Button>
            ) : null}
        </span>
    );
}

export function StudentPhone({ phone, verified }: { phone: string; verified: boolean }) {
    return (
        <span className="inline-flex flex-wrap items-center gap-2">
            {phone}
            {verified ? (
                <Badge tone="success" title="Telegram tugmasi orqali tasdiqlangan: talaba yangi Telegram hisobidan profilini o‘zi tiklay oladi.">
                    Tasdiqlangan
                </Badge>
            ) : (
                <Badge tone="secondary" title="Raqam qo‘lda kiritilgan. Talaba botdagi «Profilim» bo‘limida tasdiqlashi mumkin.">
                    Tasdiqlanmagan
                </Badge>
            )}
        </span>
    );
}

export function StudentTelegramLink() {
    const { flash } = usePage<SharedProps>().props;
    const [copied, setCopied] = useState(false);
    const link = flash.telegram_link ?? null;
    const shown = link && !link.startsWith('http') ? `/start ${link}` : link;
    if (!shown) {
        return null;
    }

    async function copy(text: string) {
        await navigator.clipboard?.writeText(text);
        setCopied(true);
    }

    return (
        <div className="mx-6 mb-6 rounded-lg border border-primary-500/40 bg-primary-500/10 p-4" role="status">
            <p className="text-sm font-medium text-heading">
                Talabaga yuboring. U havolani yangi Telegram hisobidan ochishi kerak. Havola 24 soat va faqat bir marta ishlaydi, faqat hozir ko‘rsatiladi:
            </p>
            <div className="mt-2 flex flex-wrap items-center gap-2">
                <code className="min-w-0 flex-1 rounded bg-panel px-3 py-2 text-sm break-all text-heading">{shown}</code>
                <Button size="sm" icon={copied ? 'check' : 'copy'} onClick={() => copy(shown)}>
                    {copied ? 'Nusxalandi' : 'Nusxalash'}
                </Button>
            </div>
        </div>
    );
}

import { router, useForm, usePage } from '@inertiajs/react';
import QRCode from 'qrcode';
import { FormEvent, useEffect, useState } from 'react';
import { Badge, Button, Card, CardHeader, Field, Input } from '@/Components/ui';
import type { SharedProps } from '@/types';

export type TwoFactorState = {
    enabled: boolean;
    remaining_codes: number;
    setup: { secret: string; uri: string } | null;
};

type PasswordAction = 'begin' | 'codes' | 'disable';

const ACTIONS: Record<PasswordAction, { url: string; method: 'post' | 'delete'; label: string }> = {
    begin: { url: '/profile/two-factor', method: 'post', label: 'Yoqish' },
    codes: { url: '/profile/two-factor/recovery-codes', method: 'post', label: 'Yangi zaxira kodlar' },
    disable: { url: '/profile/two-factor', method: 'delete', label: 'O‘chirish' },
};

export default function TwoFactorCard({ state }: { state: TwoFactorState }) {
    const { flash } = usePage<SharedProps>().props;
    const [action, setAction] = useState<PasswordAction | null>(null);
    const [qr, setQr] = useState<string | null>(null);
    const password = useForm({ current_password: '' });
    const confirm = useForm({ code: '' });
    const codes = flash.recovery_codes ?? null;

    useEffect(() => {
        if (!state.setup) {
            setQr(null);
            return;
        }
        QRCode.toDataURL(state.setup.uri, { margin: 1, width: 200 }).then(setQr, () => setQr(null));
    }, [state.setup]);

    function submitPassword(event: FormEvent) {
        event.preventDefault();
        if (!action) {
            return;
        }
        const { url, method } = ACTIONS[action];
        password.submit(method, url, { preserveScroll: true, onSuccess: () => { password.reset(); setAction(null); } });
    }

    function submitCode(event: FormEvent) {
        event.preventDefault();
        confirm.post('/profile/two-factor/confirm', { preserveScroll: true, onSuccess: () => confirm.reset() });
    }

    return (
        <Card>
            <CardHeader
                title="Ikki bosqichli himoya"
                description="Paroldan tashqari telefoningizdagi ilova (Google Authenticator, Microsoft Authenticator, Authy) bergan 6 xonali kod so‘raladi. Parolingiz o‘g‘irlansa ham hisobingizga kira olmaydi."
                action={state.enabled ? <Badge tone="success">Yoqilgan</Badge> : <Badge tone="secondary">O‘chiq</Badge>}
            />
            <div className="space-y-4 px-6 pb-6">
                {codes ? (
                    <div className="rounded-lg border border-warning/50 bg-warning/10 p-4" role="status">
                        <p className="text-sm font-medium text-heading">Zaxira kodlar — faqat hozir ko‘rsatiladi. Yozib oling yoki xavfsiz joyga saqlang:</p>
                        <ul className="mt-3 grid grid-cols-2 gap-2 font-mono text-sm text-heading">
                            {codes.map((code) => (
                                <li key={code} className="rounded bg-panel px-2 py-1 text-center">
                                    {code}
                                </li>
                            ))}
                        </ul>
                        <p className="mt-3 text-xs text-muted">Telefon yo‘qolsa, kod o‘rniga shulardan birini kiriting. Har bir kod bir marta ishlaydi.</p>
                    </div>
                ) : null}

                {state.setup ? (
                    <div className="grid gap-4 sm:grid-cols-[auto_1fr] sm:items-start">
                        {qr ? <img src={qr} alt="Ilova uchun QR kod" className="size-[200px] rounded-md border border-line bg-white p-1" /> : null}
                        <div className="space-y-3">
                            <p className="text-sm text-muted">1. Ilovada «+» ni bosib QR kodni skanerlang. Skanerlab bo‘lmasa, kalitni qo‘lda kiriting:</p>
                            <code className="block rounded bg-panel px-3 py-2 text-sm break-all text-heading">{state.setup.secret}</code>
                            <form onSubmit={submitCode} noValidate className="flex flex-wrap items-end gap-2">
                                <Field label="2. Ilovadagi 6 xonali kod" htmlFor="two-factor-code" error={confirm.errors.code} className="w-44">
                                    <Input
                                        id="two-factor-code"
                                        inputMode="numeric"
                                        autoComplete="one-time-code"
                                        maxLength={6}
                                        value={confirm.data.code}
                                        onChange={(event) => confirm.setData('code', event.target.value.replace(/\D/g, ''))}
                                        aria-invalid={Boolean(confirm.errors.code)}
                                    />
                                </Field>
                                <Button type="submit" disabled={confirm.processing || confirm.data.code.length !== 6}>
                                    Tasdiqlash
                                </Button>
                                <Button variant="ghost" onClick={() => router.delete('/profile/two-factor/setup', { preserveScroll: true })}>
                                    Bekor qilish
                                </Button>
                            </form>
                        </div>
                    </div>
                ) : null}

                {state.enabled ? <p className="text-sm text-muted">Qolgan zaxira kodlar: {state.remaining_codes} ta.</p> : null}

                {action ? (
                    <form onSubmit={submitPassword} noValidate className="flex flex-wrap items-end gap-2">
                        <Field label="Tasdiqlash uchun joriy parol" htmlFor="two-factor-password" error={password.errors.current_password} className="w-64">
                            <Input
                                id="two-factor-password"
                                type="password"
                                autoComplete="current-password"
                                autoFocus
                                value={password.data.current_password}
                                onChange={(event) => password.setData('current_password', event.target.value)}
                                aria-invalid={Boolean(password.errors.current_password)}
                            />
                        </Field>
                        <Button type="submit" disabled={password.processing || password.data.current_password === ''}>
                            {ACTIONS[action].label}
                        </Button>
                        <Button variant="ghost" onClick={() => { password.reset(); password.clearErrors(); setAction(null); }}>
                            Bekor qilish
                        </Button>
                    </form>
                ) : state.setup ? null : (
                    <div className="flex flex-wrap gap-2">
                        {state.enabled ? (
                            <>
                                <Button variant="secondary" onClick={() => setAction('codes')}>
                                    Yangi zaxira kodlar
                                </Button>
                                <Button variant="ghost" onClick={() => setAction('disable')}>
                                    O‘chirish
                                </Button>
                            </>
                        ) : (
                            <Button icon="shield" onClick={() => setAction('begin')}>
                                Yoqish
                            </Button>
                        )}
                    </div>
                )}
            </div>
        </Card>
    );
}

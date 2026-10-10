import { router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import TwoFactorCard, { type TwoFactorState } from '@/Components/TwoFactorCard';
import { Badge, Button, Card, CardHeader, Dl, Field, Input } from '@/Components/ui';
import AppLayout from '@/Layouts/AppLayout';
import type { SharedProps } from '@/types';

type Supervisor = {
    phone: string;
    position: string;
    telegram_linked: boolean;
    telegram_linked_at: string | null;
    notify_check_events: boolean;
};

export default function Profile({
    user,
    supervisor,
    reminderTime,
    botUsername,
    twoFactor,
}: {
    user: { name: string; login: string; email: string | null; role: string };
    supervisor: Supervisor | null;
    reminderTime: string;
    botUsername: string | null;
    twoFactor: TwoFactorState;
}) {
    const { flash } = usePage<SharedProps>().props;
    const [copied, setCopied] = useState(false);
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put('/profile/password', { preserveScroll: true, onSuccess: () => form.reset() });
    }

    async function copy(link: string) {
        await navigator.clipboard?.writeText(link);
        setCopied(true);
    }

    const link = flash.telegram_link ?? null;
    const isUrl = link?.startsWith('http') ?? false;

    return (
        <AppLayout title="Profil">
            <div className="grid gap-6 xl:grid-cols-2">
                <div className="space-y-6">
                    <Card>
                        <CardHeader title={user.name} description={user.role === 'ADMIN' ? 'Administrator' : 'Amaliyot rahbari'} />
                        <Dl
                            items={[
                                ['Login', user.login],
                                ['Email', user.email ?? '—'],
                                ...(supervisor
                                    ? ([
                                          ['Lavozim', supervisor.position],
                                          ['Telefon', supervisor.phone],
                                      ] as Array<[string, string]>)
                                    : []),
                            ]}
                        />
                    </Card>

                    <Card>
                        <CardHeader title="Parolni o‘zgartirish" description="Kamida 8 belgi. O‘zgartirilgach, boshqa qurilma va brauzerlardagi seanslar darhol yopiladi; shu oynada ishlashda davom etasiz." />
                        <form onSubmit={submit} noValidate className="grid gap-4 px-6 pb-6">
                            <Field label="Joriy parol" htmlFor="current_password" error={form.errors.current_password}>
                                <Input
                                    id="current_password"
                                    type="password"
                                    autoComplete="current-password"
                                    value={form.data.current_password}
                                    onChange={(event) => form.setData('current_password', event.target.value)}
                                    aria-invalid={Boolean(form.errors.current_password)}
                                />
                            </Field>
                            <Field label="Yangi parol" htmlFor="password" error={form.errors.password}>
                                <Input
                                    id="password"
                                    type="password"
                                    autoComplete="new-password"
                                    value={form.data.password}
                                    onChange={(event) => form.setData('password', event.target.value)}
                                    aria-invalid={Boolean(form.errors.password)}
                                />
                            </Field>
                            <Field label="Yangi parolni takrorlang" htmlFor="password_confirmation" error={form.errors.password_confirmation}>
                                <Input
                                    id="password_confirmation"
                                    type="password"
                                    autoComplete="new-password"
                                    value={form.data.password_confirmation}
                                    onChange={(event) => form.setData('password_confirmation', event.target.value)}
                                />
                            </Field>
                            <div>
                                <Button type="submit" disabled={form.processing || !form.data.current_password || !form.data.password}>
                                    Parolni saqlash
                                </Button>
                            </div>
                        </form>
                    </Card>

                    <TwoFactorCard state={twoFactor} />
                </div>

                {supervisor ? (
                    <div className="space-y-6">
                        <Card>
                            <CardHeader
                                title="Telegram bot"
                                description={`${botUsername ? `@${botUsername}` : 'Bot'} orqali talabalaringiz kelgan-ketganini darhol bilasiz, soat ${reminderTime} da esa bugun kelmaganlar ro‘yxati keladi — har birini bitta tugma bilan «Keldi» deb belgilaysiz.`}
                                action={supervisor.telegram_linked ? <Badge tone="success">Ulangan</Badge> : <Badge tone="secondary">Ulanmagan</Badge>}
                            />
                            <div className="space-y-4 px-6 pb-6">
                                {supervisor.telegram_linked ? (
                                    <p className="text-sm text-muted">Ulangan vaqt: {supervisor.telegram_linked_at ?? '—'}. Boshqa Telegram akkauntga o‘tish uchun yangi havola yarating.</p>
                                ) : (
                                    <p className="text-sm text-muted">«Havola yaratish» tugmasini bosing, havolani telefoningizda oching va botda «Start» ni bosing. Havola 24 soat va faqat bir marta ishlaydi.</p>
                                )}

                                {link ? (
                                    <div className="rounded-lg border border-primary-500/40 bg-primary-500/10 p-4" role="status">
                                        <p className="text-sm font-medium text-heading">
                                            {isUrl ? 'Havola faqat hozir ko‘rsatiladi:' : 'Botga quyidagi buyruqni yuboring (faqat hozir ko‘rsatiladi):'}
                                        </p>
                                        <div className="mt-2 flex flex-wrap items-center gap-2">
                                            <code className="min-w-0 flex-1 rounded bg-panel px-3 py-2 text-sm break-all text-heading">{isUrl ? link : `/start ${link}`}</code>
                                            <Button size="sm" icon={copied ? 'check' : 'copy'} onClick={() => copy(isUrl ? link : `/start ${link}`)}>
                                                {copied ? 'Nusxalandi' : 'Nusxalash'}
                                            </Button>
                                            {isUrl ? (
                                                <a
                                                    href={link}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="inline-flex items-center rounded-md bg-primary-500 px-3 py-1.5 text-[0.8125rem] font-medium text-white hover:bg-primary-600"
                                                >
                                                    Telegramda ochish
                                                </a>
                                            ) : null}
                                        </div>
                                    </div>
                                ) : null}

                                <div className="flex flex-wrap gap-2">
                                    <Button icon="link" onClick={() => { setCopied(false); router.post('/profile/telegram', {}, { preserveScroll: true }); }}>
                                        {supervisor.telegram_linked ? 'Yangi havola yaratish' : 'Havola yaratish'}
                                    </Button>
                                    {supervisor.telegram_linked ? (
                                        <Button
                                            variant="secondary"
                                            onClick={() => window.confirm('Telegramni uzasizmi? Bildirishnomalar kelmay qoladi.') && router.delete('/profile/telegram', { preserveScroll: true })}
                                        >
                                            Uzish
                                        </Button>
                                    ) : null}
                                </div>
                            </div>
                        </Card>

                        <Card>
                            <CardHeader title="Bildirishnomalar" />
                            <div className="space-y-3 px-6 pb-6">
                                <label className="flex items-start gap-3">
                                    <input
                                        type="checkbox"
                                        className="mt-1 size-4 accent-primary-500"
                                        checked={supervisor.notify_check_events}
                                        onChange={(event) => router.put('/profile/notifications', { notify_check_events: event.target.checked }, { preserveScroll: true })}
                                    />
                                    <span>
                                        <span className="block font-medium text-heading">Har bir kelish va ketish haqida xabar</span>
                                        <span className="block text-sm text-muted">Talaba botda kelish yoki ketishni qayd etganda darhol xabar keladi.</span>
                                    </span>
                                </label>
                                <p className="text-sm text-muted">Soat {reminderTime} dagi kunlik ro‘yxat har doim yuboriladi (ro‘yxat bo‘sh bo‘lsa yuborilmaydi).</p>
                            </div>
                        </Card>
                    </div>
                ) : null}
            </div>
        </AppLayout>
    );
}

import { Link, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import Icon from '@/Components/Icon';
import { Button, Card, Field, Input } from '@/Components/ui';
import GuestLayout from '@/Layouts/GuestLayout';

export default function TwoFactorChallenge() {
    const [recovery, setRecovery] = useState(false);
    const form = useForm({ code: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/two-factor-challenge', { onError: () => form.reset('code') });
    }

    function toggle() {
        setRecovery(!recovery);
        form.reset('code');
        form.clearErrors();
    }

    return (
        <GuestLayout title="Tasdiqlash kodi">
            <Card className="px-6 py-8 sm:px-10 sm:py-10">
                <div className="mb-6 flex items-center justify-center">
                    <span className="inline-flex size-11 items-center justify-center rounded-full bg-primary-500/15 text-primary-600 dark:text-primary-300">
                        <Icon name="shield" />
                    </span>
                </div>
                <h1 className="text-center text-xl">Ikki bosqichli himoya</h1>
                <p className="mt-1 mb-6 text-center text-muted">
                    {recovery ? 'Saqlab qo‘ygan zaxira kodlaringizdan birini kiriting.' : 'Telefoningizdagi ilova ko‘rsatayotgan 6 xonali kodni kiriting.'}
                </p>
                <form onSubmit={submit} className="space-y-5" noValidate>
                    <Field label={recovery ? 'Zaxira kod' : 'Kod'} htmlFor="code" error={form.errors.code}>
                        <Input
                            id="code"
                            key={recovery ? 'recovery' : 'totp'}
                            autoFocus
                            inputMode={recovery ? 'text' : 'numeric'}
                            autoComplete="one-time-code"
                            placeholder={recovery ? 'XXXXX-XXXXX' : '123456'}
                            maxLength={recovery ? 11 : 6}
                            className="text-center font-mono text-lg tracking-widest"
                            value={form.data.code}
                            onChange={(event) => form.setData('code', recovery ? event.target.value.toUpperCase() : event.target.value.replace(/\D/g, ''))}
                            aria-invalid={form.errors.code ? true : undefined}
                        />
                    </Field>
                    <Button type="submit" className="w-full" disabled={form.processing || form.data.code.length < 6}>
                        {form.processing ? 'Tekshirilmoqda…' : 'Kirish'}
                    </Button>
                </form>
                <div className="mt-6 space-y-2 text-center text-sm">
                    <button type="button" className="font-medium text-primary-600 hover:underline dark:text-primary-300" onClick={toggle}>
                        {recovery ? 'Ilovadagi kod bilan kirish' : 'Telefon yonimda emas — zaxira kod bilan kirish'}
                    </button>
                    <p className="text-muted">
                        Ikkalasi ham yo‘qmi? Administratorga murojaat qiling. <Link href="/login" className="text-primary-600 hover:underline dark:text-primary-300">Boshqa hisob bilan kirish</Link>
                    </p>
                </div>
            </Card>
        </GuestLayout>
    );
}

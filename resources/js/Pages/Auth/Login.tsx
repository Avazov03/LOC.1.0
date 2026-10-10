import { useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import Icon from '@/Components/Icon';
import { Button, Card, Field, Input } from '@/Components/ui';
import GuestLayout from '@/Layouts/GuestLayout';

export default function Login() {
    const form = useForm({ login: '', password: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    }

    return (
        <GuestLayout title="Kirish">
            <Card className="px-6 py-8 sm:px-10 sm:py-10">
                <div className="mb-8 flex items-center justify-center gap-2.5">
                    <span className="inline-flex size-9 items-center justify-center rounded-md bg-primary-500 text-white">
                        <Icon name="briefcase" />
                    </span>
                    <span className="text-2xl font-bold tracking-wide text-heading">Amaliyot</span>
                </div>
                <h1 className="text-xl">Boshqaruv paneli</h1>
                <p className="mt-1 mb-6 text-muted">Admin yoki amaliyot rahbari hisobi bilan kiring.</p>
                <form onSubmit={submit} className="space-y-5" noValidate>
                    <Field label="Login" htmlFor="login" error={form.errors.login}>
                        <Input
                            id="login"
                            value={form.data.login}
                            onChange={(event) => form.setData('login', event.target.value)}
                            autoFocus
                            autoComplete="username"
                            aria-invalid={form.errors.login ? true : undefined}
                            aria-describedby={form.errors.login ? 'login-error' : undefined}
                        />
                    </Field>
                    <Field label="Parol" htmlFor="password" error={form.errors.password}>
                        <Input
                            id="password"
                            type="password"
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.target.value)}
                            autoComplete="current-password"
                            aria-invalid={form.errors.password ? true : undefined}
                        />
                    </Field>
                    <Button type="submit" className="w-full" disabled={form.processing}>
                        {form.processing ? 'Tekshirilmoqda…' : 'Kirish'}
                    </Button>
                </form>
                <div className="mt-6 space-y-1 text-center text-sm text-muted">
                    <p>Parolni unutdingizmi? Universitet administratoriga murojaat qiling: u yangi parol o‘rnatib beradi.</p>
                    <p>Talabalar tizimga Telegram bot orqali kiradi.</p>
                </div>
            </Card>
        </GuestLayout>
    );
}

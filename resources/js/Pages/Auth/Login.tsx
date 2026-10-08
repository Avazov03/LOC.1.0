import { useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import { FormEvent } from 'react';

export default function Login() {
    const form = useForm({ login: '', password: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/login');
    }

    return (
        <GuestLayout>
            <div className="card px-sm-6 px-0">
                <div className="card-body">
                    <div className="app-brand justify-content-center mb-4">
                        <span className="app-brand-text demo text-heading fw-bold">Amaliyot</span>
                    </div>
                    <h4 className="mb-1">Boshqaruv paneli</h4>
                    <p className="mb-6 text-body-secondary">Admin yoki rahbar hisobi bilan kiring</p>
                    <form onSubmit={submit}>
                        <div className="mb-4">
                            <label className="form-label" htmlFor="login">Login</label>
                            <input id="login" className="form-control" value={form.data.login} onChange={(event) => form.setData('login', event.target.value)} autoFocus autoComplete="username" />
                            {form.errors.login ? <div className="text-danger small mt-1">{form.errors.login}</div> : null}
                        </div>
                        <div className="mb-4">
                            <label className="form-label" htmlFor="password">Parol</label>
                            <input id="password" type="password" className="form-control" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} autoComplete="current-password" />
                        </div>
                        <button className="btn btn-primary d-grid w-100" type="submit" disabled={form.processing}>Kirish</button>
                    </form>
                </div>
            </div>
        </GuestLayout>
    );
}

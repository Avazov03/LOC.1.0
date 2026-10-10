import { Head, usePage } from '@inertiajs/react';
import { ReactNode } from 'react';
import type { SharedProps } from '@/types';

export default function GuestLayout({ title, children }: { title: string; children: ReactNode }) {
    const error = usePage<SharedProps>().props.flash?.error;

    return (
        <div className="relative flex min-h-screen items-center justify-center overflow-hidden px-4 py-10">
            <Head title={title} />
            <div className="pointer-events-none absolute top-1/2 left-1/2 -z-0 hidden size-[30rem] -translate-x-[60%] -translate-y-[55%] rounded-full bg-primary-500/10 blur-3xl sm:block" aria-hidden="true" />
            <div className="relative w-full max-w-md">
                {error ? (
                    <p className="mb-4 rounded-md border border-danger/40 bg-danger/10 px-4 py-3 text-sm text-danger" role="alert">
                        {error}
                    </p>
                ) : null}
                {children}
            </div>
        </div>
    );
}

import { Head } from '@inertiajs/react';
import { ReactNode } from 'react';

export default function GuestLayout({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div className="relative flex min-h-screen items-center justify-center overflow-hidden px-4 py-10">
            <Head title={title} />
            <div className="pointer-events-none absolute top-1/2 left-1/2 -z-0 hidden size-[30rem] -translate-x-[60%] -translate-y-[55%] rounded-full bg-primary-500/10 blur-3xl sm:block" aria-hidden="true" />
            <div className="relative w-full max-w-md">{children}</div>
        </div>
    );
}

import { Head, Link, router, usePage } from '@inertiajs/react';
import { ReactNode, useEffect, useState } from 'react';
import Icon from '@/Components/Icon';
import { cx } from '@/Components/ui';
import type { SharedProps } from '@/types';

const roleLabels: Record<string, string> = { ADMIN: 'Administrator', SUPERVISOR: 'Amaliyot rahbari' };

function useTheme(): [boolean, () => void] {
    const [dark, setDark] = useState(() => document.documentElement.classList.contains('dark'));

    function toggle() {
        const next = !dark;
        document.documentElement.classList.toggle('dark', next);
        localStorage.setItem('amaliyot_theme', next ? 'dark' : 'light');
        setDark(next);
    }

    return [dark, toggle];
}

export default function AppLayout({ title, children }: { title: string; children: ReactNode }) {
    const { auth, navigation, flash, appName } = usePage<SharedProps>().props;
    const url = usePage().url;
    const [menuOpen, setMenuOpen] = useState(false);
    const [dark, toggleTheme] = useTheme();

    useEffect(() => setMenuOpen(false), [url]);

    const matches = (href: string) => href !== '' && (url === href || url.startsWith(`${href}/`) || url.startsWith(`${href}?`));
    // Only the most specific item is current, so /attendance/policies does not also light up /attendance.
    const activeHref = navigation
        .map((item) => item.href ?? '')
        .filter(matches)
        .sort((a, b) => b.length - a.length)[0];
    const isActive = (href: string) => href !== '' && href === activeHref;

    return (
        <div className="min-h-screen">
            <Head title={title} />

            <aside
                className={cx(
                    'fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-panel shadow-panel transition-transform duration-200 lg:translate-x-0',
                    menuOpen ? 'translate-x-0' : '-translate-x-full',
                )}
                aria-label="Asosiy menyu"
            >
                <div className="flex h-16 items-center justify-between px-6">
                    <Link href="/dashboard" className="flex items-center gap-2.5">
                        <span className="inline-flex size-8 items-center justify-center rounded-md bg-primary-500 text-white">
                            <Icon name="briefcase" className="size-[1.125rem]" />
                        </span>
                        <span className="text-xl font-bold tracking-wide text-heading">{appName}</span>
                    </Link>
                    <button type="button" className="rounded-md p-1 text-muted lg:hidden" onClick={() => setMenuOpen(false)} aria-label="Menyuni yopish">
                        <Icon name="close" />
                    </button>
                </div>

                <nav className="flex-1 overflow-y-auto px-3.5 pb-6">
                    <ul className="space-y-0.5">
                        {navigation.map((item, index) =>
                            item.header ? (
                                <li key={`h-${item.header}`} className={cx('px-3 pb-1.5 text-xs tracking-wider text-muted uppercase', index === 0 ? 'pt-2' : 'pt-5')}>
                                    {item.header}
                                </li>
                            ) : (
                                <li key={item.href}>
                                    <Link
                                        href={item.href ?? '/dashboard'}
                                        className={cx(
                                            'flex items-center gap-3 rounded-md px-3 py-2 transition-colors',
                                            isActive(item.href ?? '')
                                                ? 'bg-primary-500/15 font-semibold text-primary-600 dark:text-primary-300'
                                                : 'text-heading hover:bg-heading/5',
                                        )}
                                        aria-current={isActive(item.href ?? '') ? 'page' : undefined}
                                    >
                                        {item.icon ? <Icon name={item.icon} className="size-5" /> : null}
                                        <span>{item.label}</span>
                                    </Link>
                                </li>
                            ),
                        )}
                    </ul>
                </nav>
            </aside>

            {menuOpen ? <div className="fixed inset-0 z-30 bg-[#22303e]/50 lg:hidden" onClick={() => setMenuOpen(false)} aria-hidden="true" /> : null}

            <div className="flex min-h-screen flex-col lg:pl-64">
                <header className="sticky top-0 z-20 px-4 pt-4 sm:px-6">
                    <div className="mx-auto flex h-16 max-w-[1440px] items-center gap-3 rounded-lg bg-panel/95 px-4 shadow-panel backdrop-blur sm:px-6">
                        <button type="button" className="-ml-1 rounded-md p-1.5 text-heading lg:hidden" onClick={() => setMenuOpen(true)} aria-label="Menyuni ochish">
                            <Icon name="menu" />
                        </button>
                        <h1 className="min-w-0 truncate text-base font-medium">{title}</h1>
                        <div className="ml-auto flex items-center gap-2 sm:gap-4">
                            <button
                                type="button"
                                onClick={toggleTheme}
                                className="rounded-md p-2 text-heading transition hover:bg-heading/5"
                                aria-label={dark ? 'Yorug‘ mavzu' : 'Qorong‘i mavzu'}
                            >
                                <Icon name={dark ? 'sun' : 'moon'} />
                            </button>
                            <div className="hidden text-right leading-tight sm:block">
                                <p className="text-sm font-medium text-heading">{auth.user?.name}</p>
                                <p className="text-xs text-muted">{roleLabels[auth.user?.role ?? ''] ?? ''}</p>
                            </div>
                            <button
                                type="button"
                                onClick={() => router.post('/logout')}
                                className="inline-flex items-center gap-1.5 rounded-md bg-secondary/15 px-3 py-1.5 text-[0.8125rem] font-medium text-secondary transition hover:bg-secondary/25"
                            >
                                <Icon name="logout" className="size-4" />
                                <span className="hidden sm:inline">Chiqish</span>
                            </button>
                        </div>
                    </div>
                    {auth.impersonating ? (
                        <div
                            className="mx-auto mt-2 flex max-w-[1440px] flex-wrap items-center justify-between gap-3 rounded-lg border border-warning/50 bg-[#fff6e0] px-4 py-2.5 text-sm text-heading shadow-panel dark:bg-[#4a3b1a]"
                            role="status"
                        >
                            <span className="flex items-center gap-2">
                                <Icon name="alert" className="size-4 shrink-0 text-[#e09600] dark:text-warning" />
                                <span>
                                    Siz <b>{auth.user?.name}</b> sifatida ishlayapsiz. Har bir amal audit jurnalida sizning nomingiz bilan belgilanadi.
                                </span>
                            </span>
                            <button
                                type="button"
                                onClick={() => router.post('/impersonate/leave')}
                                className="rounded-md bg-primary-500 px-3 py-1.5 text-[0.8125rem] font-medium whitespace-nowrap text-white hover:bg-primary-600"
                            >
                                Admin hisobiga qaytish
                            </button>
                        </div>
                    ) : null}
                </header>

                <main className="mx-auto w-full max-w-[1440px] flex-1 px-4 py-6 sm:px-6">
                    {flash.success ? (
                        <div className="mb-6 flex items-center gap-2 rounded-md bg-success/15 px-4 py-3 text-sm text-[#56ca00] dark:text-success" role="status">
                            <Icon name="check" className="size-4" />
                            {flash.success}
                        </div>
                    ) : null}
                    {flash.error ? (
                        <div className="mb-6 flex items-center gap-2 rounded-md bg-danger/15 px-4 py-3 text-sm text-danger" role="alert">
                            <Icon name="alert" className="size-4" />
                            {flash.error}
                        </div>
                    ) : null}
                    {children}
                </main>

                <footer className="mx-auto w-full max-w-[1440px] px-4 pb-6 text-sm text-muted sm:px-6">
                    {appName}
                    {auth.user?.university ? ` · ${auth.user.university}` : ''}
                </footer>
            </div>
        </div>
    );
}

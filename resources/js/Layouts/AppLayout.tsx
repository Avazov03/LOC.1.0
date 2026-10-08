import { Link, router, usePage } from '@inertiajs/react';
import { ReactNode, useEffect, useState } from 'react';
import type { SharedProps } from '@/types';

export default function AppLayout({ title, children }: { title: string; children: ReactNode }) {
    const { auth, navigation, flash, appName } = usePage<SharedProps>().props;
    const url = usePage().url;
    const [open, setOpen] = useState(false);

    useEffect(() => {
        document.documentElement.className = 'layout-navbar-fixed layout-menu-fixed layout-compact';
        document.documentElement.classList.toggle('layout-menu-expanded', open);
    }, [open]);

    function toggleTheme() {
        const next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-bs-theme', next);
        localStorage.setItem('zon_admin_theme', next);
    }

    return (
        <div className="layout-wrapper layout-content-navbar">
            <div className="layout-container">
                <aside id="layout-menu" className="layout-menu menu-vertical menu bg-menu-theme">
                    <div className="app-brand demo">
                        <Link href="/dashboard" className="app-brand-link">
                            <span className="app-brand-logo demo text-primary">
                                <i className="bx bx-briefcase bx-sm" />
                            </span>
                            <span className="app-brand-text demo menu-text fw-bold ms-2">{appName}</span>
                        </Link>
                    </div>
                    <ul className="menu-inner py-1">
                        {navigation.map((item, index) =>
                            item.header ? (
                                <li key={item.header} className="menu-header zon-work-header small text-uppercase">
                                    <span className="menu-header-text">{item.header}</span>
                                </li>
                            ) : (
                                <li key={item.href ?? index} className={`menu-item zon-work-item${url.startsWith(item.href ?? '') ? ' active' : ''}`}>
                                    <Link href={item.href ?? '/dashboard'} className="menu-link" onClick={() => setOpen(false)}>
                                        <i className={`menu-icon icon-base bx ${item.icon ?? 'bx-circle'}`} />
                                        <div>{item.label}</div>
                                    </Link>
                                </li>
                            ),
                        )}
                    </ul>
                </aside>
                <div className="layout-page">
                    <nav className="layout-navbar container-xxl navbar-detached navbar navbar-expand-xl align-items-center bg-navbar-theme">
                        <div className="layout-menu-toggle navbar-nav align-items-xl-center me-4 me-xl-0 d-xl-none">
                            <button type="button" className="nav-item nav-link px-0 me-xl-6 btn btn-link" onClick={() => setOpen((value) => !value)} aria-label="Menyu">
                                <i className="icon-base bx bx-menu bx-md" />
                            </button>
                        </div>
                        <div className="navbar-nav-right d-flex align-items-center w-100" id="navbar-collapse">
                            <div className="navbar-nav align-items-center">
                                <span className="fw-medium">{title}</span>
                            </div>
                            <ul className="navbar-nav flex-row align-items-center ms-auto">
                                <li className="nav-item me-3">
                                    <button type="button" className="btn btn-sm btn-text-secondary" onClick={toggleTheme} aria-label="Mavzu">
                                        <i className="bx bx-moon" />
                                    </button>
                                </li>
                                <li className="nav-item d-flex align-items-center gap-3">
                                    <span className="fw-medium d-none d-md-inline">{auth.user?.name}</span>
                                    <button type="button" className="btn btn-sm btn-label-secondary" onClick={() => router.post('/logout')}>
                                        Chiqish
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </nav>
                    <div className="content-wrapper">
                        <div className="container-xxl flex-grow-1 container-p-y" data-zon-ready>
                            {flash.success ? <div className="alert alert-success">{flash.success}</div> : null}
                            {flash.error ? <div className="alert alert-danger">{flash.error}</div> : null}
                            {children}
                        </div>
                        <footer className="content-footer footer bg-footer-theme">
                            <div className="container-xxl py-4">
                                <span className="text-body-secondary">{appName} — amaliyot boshqaruvi</span>
                            </div>
                        </footer>
                    </div>
                </div>
            </div>
            {open ? <div className="layout-overlay layout-menu-toggle" onClick={() => setOpen(false)} /> : null}
        </div>
    );
}

/// <reference types="vite/client" />

export type Role = 'ADMIN' | 'SUPERVISOR' | 'STUDENT';

export type NavEntry = {
    header?: string;
    label?: string;
    href?: string;
    icon?: string;
};

export type SharedProps = {
    auth: {
        user: { name: string; login: string | null; role: Role } | null;
    };
    navigation: NavEntry[];
    flash: { success?: string | null; error?: string | null };
    appName: string;
};

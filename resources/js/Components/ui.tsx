import { Link } from '@inertiajs/react';
import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react';
import Icon, { type IconName } from '@/Components/Icon';
import type { Paginated } from '@/types';

export type Tone = 'primary' | 'success' | 'info' | 'warning' | 'danger' | 'secondary';

const toneClasses: Record<Tone, string> = {
    primary: 'bg-primary-500/15 text-primary-600 dark:text-primary-300',
    success: 'bg-success/15 text-[#56ca00] dark:text-success',
    info: 'bg-info/15 text-[#00a7cc] dark:text-info',
    warning: 'bg-warning/15 text-[#e09600] dark:text-warning',
    danger: 'bg-danger/15 text-danger',
    secondary: 'bg-secondary/15 text-secondary',
};

export function cx(...classes: Array<string | false | null | undefined>): string {
    return classes.filter(Boolean).join(' ');
}

export function Card({ children, className }: { children: ReactNode; className?: string }) {
    return <section className={cx('rounded-lg bg-panel shadow-panel', className)}>{children}</section>;
}

export function CardHeader({ title, description, action }: { title: string; description?: string; action?: ReactNode }) {
    return (
        <header className="flex flex-wrap items-start justify-between gap-4 px-6 pt-6 pb-4">
            <div className="min-w-0">
                <h2 className="text-lg leading-tight">{title}</h2>
                {description ? <p className="mt-1 text-sm text-muted">{description}</p> : null}
            </div>
            {action}
        </header>
    );
}

type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'tonal';

const buttonVariants: Record<ButtonVariant, string> = {
    primary: 'bg-primary-500 text-white shadow-[0_0.125rem_0.25rem_0_rgb(105_108_255/0.4)] hover:bg-primary-600',
    secondary: 'bg-secondary/15 text-secondary hover:bg-secondary/25',
    tonal: 'bg-primary-500/15 text-primary-600 hover:bg-primary-500/25 dark:text-primary-300',
    ghost: 'text-body hover:bg-heading/5',
};

export function Button({
    variant = 'primary',
    size = 'md',
    icon,
    children,
    className,
    type = 'button',
    ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: ButtonVariant; size?: 'sm' | 'md'; icon?: IconName }) {
    return (
        <button
            type={type}
            className={cx(
                'inline-flex items-center justify-center gap-1.5 rounded-md font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-60',
                size === 'sm' ? 'px-3 py-1.5 text-[0.8125rem]' : 'px-5 py-2 text-[0.9375rem]',
                buttonVariants[variant],
                className,
            )}
            {...props}
        >
            {icon ? <Icon name={icon} className={size === 'sm' ? 'size-4' : 'size-[1.125rem]'} /> : null}
            {children}
        </button>
    );
}

const controlClass =
    'block w-full rounded-md border border-line bg-panel px-3.5 py-2 text-[0.9375rem] text-heading placeholder:text-muted transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 focus:outline-none aria-invalid:border-danger';

export function Field({ label, htmlFor, error, children, className }: { label: string; htmlFor: string; error?: string; children: ReactNode; className?: string }) {
    return (
        <div className={className}>
            <label htmlFor={htmlFor} className="mb-1.5 block text-[0.8125rem] font-medium text-heading uppercase tracking-wide">
                {label}
            </label>
            {children}
            {error ? (
                <p id={`${htmlFor}-error`} className="mt-1.5 text-[0.8125rem] text-danger" role="alert">
                    {error}
                </p>
            ) : null}
        </div>
    );
}

export function Input({ className, ...props }: InputHTMLAttributes<HTMLInputElement>) {
    return <input className={cx(controlClass, className)} {...props} />;
}

export function Select({ className, children, ...props }: SelectHTMLAttributes<HTMLSelectElement>) {
    return (
        <select className={cx(controlClass, 'pr-9', className)} {...props}>
            {children}
        </select>
    );
}

export function Textarea({ className, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement>) {
    return <textarea className={cx(controlClass, 'min-h-24', className)} {...props} />;
}

export function Dl({ items }: { items: Array<[string, ReactNode]> }) {
    return (
        <dl className="grid gap-x-6 gap-y-3 px-6 pb-6 sm:grid-cols-2 lg:grid-cols-4">
            {items.map(([label, value]) => (
                <div key={label}>
                    <dt className="text-[0.8125rem] font-medium tracking-wide text-muted uppercase">{label}</dt>
                    <dd className="mt-0.5 text-heading">{value ?? '—'}</dd>
                </div>
            ))}
        </dl>
    );
}

export function Badge({ tone, children }: { tone: Tone; children: ReactNode }) {
    return <span className={cx('inline-flex items-center rounded px-2 py-0.5 text-xs font-semibold', toneClasses[tone])}>{children}</span>;
}

export function IconTile({ icon, tone, className }: { icon: IconName; tone: Tone; className?: string }) {
    return (
        <span className={cx('inline-flex size-10 shrink-0 items-center justify-center rounded-md', toneClasses[tone], className)}>
            <Icon name={icon} className="size-5" />
        </span>
    );
}

export function Table({ head, children }: { head: ReactNode[]; children: ReactNode }) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-left text-[0.9375rem]">
                <thead>
                    <tr className="border-y border-line">
                        {head.map((cell, index) => (
                            <th key={index} scope="col" className="px-6 py-3 text-[0.8125rem] font-medium tracking-wide text-heading uppercase whitespace-nowrap">
                                {cell}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-line">{children}</tbody>
            </table>
        </div>
    );
}

export function Td({ children, className }: { children?: ReactNode; className?: string }) {
    return <td className={cx('px-6 py-3.5 align-middle', className)}>{children}</td>;
}

export function EmptyRow({ colSpan, children }: { colSpan: number; children: ReactNode }) {
    return (
        <tr>
            <td colSpan={colSpan} className="px-6 py-12 text-center text-muted">
                {children}
            </td>
        </tr>
    );
}

export function StatusBadge({ status }: { status: string }) {
    const map: Record<string, [Tone, string]> = {
        ACTIVE: ['success', 'Faol'],
        INACTIVE: ['secondary', 'Nofaol'],
        CLOSED: ['secondary', 'Yopilgan'],
        BLOCKED: ['danger', 'Bloklangan'],
        EXPIRED: ['warning', 'Muddati tugagan'],
        PENDING: ['warning', 'Kutilmoqda'],
        ENDED: ['secondary', 'Yakunlangan'],
        CANCELLED: ['danger', 'Bekor qilingan'],
        APPROVED: ['success', 'Tasdiqlangan'],
        REJECTED: ['danger', 'Rad etilgan'],
    };
    const [tone, label] = map[status] ?? ['secondary', status];

    return <Badge tone={tone}>{label}</Badge>;
}

export function Pagination<T>({ page }: { page: Paginated<T> }) {
    if (page.last_page <= 1) {
        return null;
    }

    const linkClass = 'inline-flex size-9 items-center justify-center rounded-md bg-heading/5 text-heading transition hover:bg-primary-500/15 hover:text-primary-600';

    return (
        <nav className="flex items-center justify-between gap-4 px-6 py-4" aria-label="Sahifalar">
            <p className="text-sm text-muted">
                {page.from}–{page.to} / {page.total}
            </p>
            <div className="flex items-center gap-2">
                {page.prev_page_url ? (
                    <Link href={page.prev_page_url} preserveScroll className={linkClass} aria-label="Oldingi sahifa">
                        <Icon name="chevronLeft" className="size-4" />
                    </Link>
                ) : null}
                <span className="px-2 text-sm text-heading">
                    {page.current_page} / {page.last_page}
                </span>
                {page.next_page_url ? (
                    <Link href={page.next_page_url} preserveScroll className={linkClass} aria-label="Keyingi sahifa">
                        <Icon name="chevronRight" className="size-4" />
                    </Link>
                ) : null}
            </div>
        </nav>
    );
}

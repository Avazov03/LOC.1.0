import { Link, router } from '@inertiajs/react';
import { Select, cx } from '@/Components/ui';

/** localStorage key of the supervisor's last opened group (internship id). */
export const LAST_GROUP_KEY = 'loc.lastGroup';

export type SwitcherItem = { key: string; label: string; count?: number; href: string };

/** Above this many groups the chips turn into one select so the row never wraps into a wall of buttons. */
const CHIP_LIMIT = 8;

/**
 * One-click switch between the groups a user works with. `active` is the key of the shown group (or "all").
 */
export default function GroupSwitcher({ items, active, label = 'Guruh' }: { items: SwitcherItem[]; active: string; label?: string }) {
    if (items.length < 2) {
        return null;
    }

    if (items.length > CHIP_LIMIT) {
        return (
            <label className="flex items-center gap-3 text-sm text-muted">
                <span className="shrink-0">{label}:</span>
                <Select className="max-w-xs" value={active} onChange={(event) => router.visit(items.find((item) => item.key === event.target.value)?.href ?? items[0].href, { preserveScroll: true })}>
                    {items.map((item) => (
                        <option key={item.key} value={item.key}>
                            {item.label}
                            {item.count !== undefined ? ` (${item.count})` : ''}
                        </option>
                    ))}
                </Select>
            </label>
        );
    }

    return (
        <nav aria-label={label} className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
            {items.map((item) => {
                const current = item.key === active;
                return (
                    <Link
                        key={item.key}
                        href={item.href}
                        preserveScroll
                        aria-current={current ? 'page' : undefined}
                        className={cx(
                            'inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors',
                            current
                                ? 'border-primary-500 bg-primary-500 text-white'
                                : 'border-line text-body hover:border-primary-400 hover:text-primary-600',
                        )}
                    >
                        {item.label}
                        {item.count !== undefined ? <span className={cx('text-xs tabular-nums', current ? 'text-white/80' : 'text-muted')}>{item.count}</span> : null}
                    </Link>
                );
            })}
        </nav>
    );
}

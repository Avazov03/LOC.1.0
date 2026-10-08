import AppLayout from '@/Layouts/AppLayout';
import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types';

type Stat = { label: string; value: number; icon: string; tone: string };

export default function Dashboard({ stats, yearCount }: { stats: Stat[]; yearCount: number }) {
    const { auth } = usePage<SharedProps>().props;
    const admin = auth.user?.role === 'ADMIN';

    return (
        <AppLayout title="Boshqaruv">
            <div className="mb-6">
                <h4 className="mb-1">Xush kelibsiz, {auth.user?.name}</h4>
                <p className="text-body-secondary mb-0">
                    {admin
                        ? 'Akademik tuzilmani shu yerdan boshqarasiz. Amaliyot, davomat va Telegram keyingi bosqichlarda shu panelga ulanadi.'
                        : 'Sizga biriktirilgan guruhlar va davomat shu paneldan ochiladi.'}
                </p>
            </div>
            {admin ? (
                <div className="row g-6">
                    {stats.map((stat) => (
                        <div className="col-sm-6 col-xl-3" key={stat.label}>
                            <div className="card h-100">
                                <div className="card-body">
                                    <div className="d-flex align-items-center justify-content-between">
                                        <div>
                                            <span className="d-block mb-1 text-body-secondary">{stat.label}</span>
                                            <h4 className="mb-0">{stat.value.toLocaleString('uz-UZ')}</h4>
                                        </div>
                                        <span className={`avatar avatar-initial rounded bg-label-${stat.tone}`}>
                                            <i className={`bx ${stat.icon}`} />
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    ))}
                    <div className="col-12">
                        <div className="card">
                            <div className="card-body">
                                <h5 className="card-title">O‘quv yillari</h5>
                                <p className="mb-0 text-body-secondary">{yearCount.toLocaleString('uz-UZ')} ta yil yozuvi. Tarix yilma-yil alohida saqlanadi.</p>
                            </div>
                        </div>
                    </div>
                </div>
            ) : (
                <div className="card">
                    <div className="card-body text-center py-5">
                        <span className="avatar avatar-initial rounded bg-label-primary mb-3">
                            <i className="bx bx-group" />
                        </span>
                        <h5>Guruhlar hali biriktirilmagan</h5>
                        <p className="text-body-secondary mb-0">Amaliyot guruhi va talabalar keyingi bosqichda shu yerga chiqadi. Hozircha faqat o‘z hisobingizni ko‘rasiz.</p>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}

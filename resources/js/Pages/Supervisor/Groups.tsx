import AppLayout from '@/Layouts/AppLayout';

export default function Groups() {
    return (
        <AppLayout title="Mening guruhlarim">
            <div className="card">
                <div className="card-body text-center py-5">
                    <span className="avatar avatar-initial rounded bg-label-info mb-3">
                        <i className="bx bx-group" />
                    </span>
                    <h5>Hali guruh biriktirilmagan</h5>
                    <p className="text-body-secondary mb-0">Admin amaliyot guruhini sizga bog‘lagach, talabalar va davomat shu yerda chiqadi.</p>
                </div>
            </div>
        </AppLayout>
    );
}

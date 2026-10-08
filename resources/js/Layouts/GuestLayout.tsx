import { ReactNode, useEffect } from 'react';

export default function GuestLayout({ children }: { children: ReactNode }) {
    useEffect(() => {
        document.documentElement.className = 'layout-wide customizer-hide';
    }, []);

    return (
        <div className="container-xxl">
            <div className="authentication-wrapper authentication-basic container-p-y">
                <div className="authentication-inner">{children}</div>
            </div>
        </div>
    );
}

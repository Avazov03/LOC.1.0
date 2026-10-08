import 'leaflet/dist/leaflet.css';
import L from 'leaflet';
import { useEffect, useRef } from 'react';

type Props = {
    latitude: number | null;
    longitude: number | null;
    radius: number;
    onChange?: (latitude: number, longitude: number) => void;
};

const DEFAULT_CENTER: L.LatLngTuple = [41.311081, 69.240562];

const markerIcon = L.divIcon({
    className: '',
    html: '<span style="display:block;width:18px;height:18px;border-radius:9999px;background:#696cff;border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.35)"></span>',
    iconSize: [18, 18],
    iconAnchor: [9, 9],
});

/**
 * Admin-only marker and radius preview (A43). The browser only picks the point; PostGIS decides verification later.
 */
export default function OrganizationMap({ latitude, longitude, radius, onChange }: Props) {
    const container = useRef<HTMLDivElement>(null);
    const map = useRef<L.Map | null>(null);
    const marker = useRef<L.Marker | null>(null);
    const circle = useRef<L.Circle | null>(null);
    const changeRef = useRef(onChange);
    changeRef.current = onChange;

    useEffect(() => {
        if (!container.current || map.current) {
            return;
        }

        const instance = L.map(container.current).setView(latitude !== null && longitude !== null ? [latitude, longitude] : DEFAULT_CENTER, latitude !== null ? 16 : 12);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap',
        }).addTo(instance);
        instance.on('click', (event: L.LeafletMouseEvent) => changeRef.current?.(round(event.latlng.lat), round(event.latlng.lng)));
        map.current = instance;

        return () => {
            instance.remove();
            map.current = null;
            marker.current = null;
            circle.current = null;
        };
        // The map is created once; position updates are handled below.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        const instance = map.current;
        if (!instance || latitude === null || longitude === null) {
            return;
        }

        const position: L.LatLngTuple = [latitude, longitude];
        if (!marker.current) {
            marker.current = L.marker(position, { draggable: Boolean(changeRef.current), icon: markerIcon, keyboard: true, title: 'Tashkilot nuqtasi' }).addTo(instance);
            marker.current.on('dragend', () => {
                const point = marker.current!.getLatLng();
                changeRef.current?.(round(point.lat), round(point.lng));
            });
            circle.current = L.circle(position, { radius, color: '#696cff', weight: 2, fillOpacity: 0.12 }).addTo(instance);
            instance.setView(position, Math.max(instance.getZoom(), 16));
        } else {
            marker.current.setLatLng(position);
            circle.current?.setLatLng(position);
        }
        circle.current?.setRadius(radius);
    }, [latitude, longitude, radius]);

    return (
        <div
            ref={container}
            className="h-80 w-full overflow-hidden rounded-md border border-line"
            role="application"
            aria-label={onChange ? 'Xarita: nuqtani bosib belgilang' : 'Xarita: tashkilot nuqtasi va radiusi'}
        />
    );
}

function round(value: number): number {
    return Math.round(value * 1e7) / 1e7;
}

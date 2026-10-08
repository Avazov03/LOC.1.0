import OrganizationMap from '@/Components/OrganizationMap';
import { Field, Input, Select } from '@/Components/ui';

export type OrganizationData = {
    name: string;
    type: string;
    address: string;
    contact_name: string;
    contact_phone: string;
    contact_position: string;
    website: string;
    latitude: number | null;
    longitude: number | null;
    radius_meters: number;
    status: string;
};

type Props = {
    data: OrganizationData;
    errors: Partial<Record<keyof OrganizationData, string>>;
    setData: <K extends keyof OrganizationData>(key: K, value: OrganizationData[K]) => void;
    showStatus?: boolean;
};

/**
 * Shared by the organization form and the new-organization approval. Admin-only screens.
 */
export default function OrganizationFields({ data, errors, setData, showStatus = false }: Props) {
    const text = (key: keyof OrganizationData, label: string, required = true) => (
        <Field label={label} htmlFor={key} error={errors[key]}>
            <Input
                id={key}
                value={(data[key] as string | null) ?? ''}
                onChange={(event) => setData(key, event.target.value as never)}
                required={required}
                aria-invalid={errors[key] ? true : undefined}
            />
        </Field>
    );

    return (
        <div className="grid gap-6 lg:grid-cols-2">
            <div className="space-y-4">
                {text('name', 'Nomi')}
                {text('type', 'Turi (masalan: sud, firma)')}
                {text('address', 'Manzil')}
                <div className="grid gap-4 sm:grid-cols-2">
                    {text('contact_name', 'Mas’ul shaxs')}
                    {text('contact_phone', 'Mas’ul telefoni')}
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    {text('contact_position', 'Mas’ul lavozimi', false)}
                    {text('website', 'Veb-sayt', false)}
                </div>
                {showStatus ? (
                    <Field label="Holat" htmlFor="status" error={errors.status}>
                        <Select id="status" value={data.status} onChange={(event) => setData('status', event.target.value)}>
                            <option value="ACTIVE">Faol</option>
                            <option value="INACTIVE">Nofaol</option>
                        </Select>
                    </Field>
                ) : null}
            </div>
            <div className="space-y-4">
                <OrganizationMap
                    latitude={data.latitude}
                    longitude={data.longitude}
                    radius={data.radius_meters}
                    onChange={(latitude, longitude) => {
                        setData('latitude', latitude);
                        setData('longitude', longitude);
                    }}
                />
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Kenglik (lat)" htmlFor="latitude" error={errors.latitude}>
                        <Input
                            id="latitude"
                            type="number"
                            step="0.0000001"
                            value={data.latitude ?? ''}
                            onChange={(event) => setData('latitude', event.target.value === '' ? null : Number(event.target.value))}
                            aria-invalid={errors.latitude ? true : undefined}
                        />
                    </Field>
                    <Field label="Uzunlik (lng)" htmlFor="longitude" error={errors.longitude}>
                        <Input
                            id="longitude"
                            type="number"
                            step="0.0000001"
                            value={data.longitude ?? ''}
                            onChange={(event) => setData('longitude', event.target.value === '' ? null : Number(event.target.value))}
                            aria-invalid={errors.longitude ? true : undefined}
                        />
                    </Field>
                </div>
                <Field label={`Radius: ${data.radius_meters} m`} htmlFor="radius_meters" error={errors.radius_meters}>
                    <input
                        id="radius_meters"
                        type="range"
                        min={100}
                        max={500}
                        step={10}
                        value={data.radius_meters}
                        onChange={(event) => setData('radius_meters', Number(event.target.value))}
                        className="w-full accent-primary-500"
                    />
                    <div className="flex justify-between text-xs text-muted">
                        <span>100 m</span>
                        <span>500 m</span>
                    </div>
                </Field>
            </div>
        </div>
    );
}

export type Option = { value: string; label: string };

export interface FilterOptions {
    makes: { id: number; name: string }[];
    body_types: Option[];
    conditions: Option[];
    transmissions: Option[];
    fuels: Option[];
    radii: number[];
}

export interface Filters {
    q: string | null;
    make: number[];
    model: number | null;
    body: string[];
    condition: string[];
    transmission: string | null;
    fuel: string[];
    price_min: number | null;
    price_max: number | null;
    year_min: number | null;
    year_max: number | null;
    mileage_max: number | null;
    city: string | null;
    lat: number | null;
    lng: number | null;
    radius: number | null;
    sort: string;
}

/** Drop empty values so URLs stay short and shareable. */
export function toQuery(filters: Partial<Filters>): Record<string, unknown> {
    return Object.fromEntries(
        Object.entries(filters).filter(([key, value]) => {
            if (value === null || value === undefined || value === '') return false;
            if (Array.isArray(value) && value.length === 0) return false;
            if (key === 'sort' && value === 'newest') return false;
            return true;
        }),
    );
}

export type Option = { value: string; label: string };

/** A filter choice with how many cars on sale have it (SearchFacets). */
export type Facet = { value: string; label: string; count: number };

export interface FilterOptions {
    makes: { id: number; name: string; count: number }[];
    models: { id: number; name: string; make_id: number; count: number }[];
    body_types: Facet[];
    conditions: Facet[];
    transmissions: Facet[];
    fuels: Facet[];
    drivetrains: Facet[];
    colours: Facet[];
    states: Facet[];
    cities: (Facet & { state: string | null })[];
    features: { id: number; name: string; group: string; count: number }[];
    extras: Facet[];
    years: { min: number | null; max: number | null };
    prices: { min: number | null; max: number | null };
    radii: number[];
}

export interface Filters {
    q: string | null;
    make: number[];
    model: number[];
    body: string[];
    condition: string[];
    transmission: string | null;
    fuel: string[];
    drive: string[];
    colour: string[];
    feature: number[];
    has: string[];
    price_min: number | null;
    price_max: number | null;
    year_min: number | null;
    year_max: number | null;
    mileage_max: number | null;
    city: string | null;
    state: string | null;
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

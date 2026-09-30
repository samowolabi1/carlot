import type { route as routeFn } from 'ziggy-js';

export type UserRole = 'customer' | 'staff' | 'admin';
export type LotRole = 'owner' | 'manager' | 'sales';
export type LotStatus = 'pending' | 'active' | 'suspended';

export interface AuthUser {
    ulid: string;
    name: string | null;
    phone: string;
    email: string | null;
    role: UserRole;
}

export interface LotSummary {
    slug: string;
    name: string;
    initials: string;
    role: LotRole;
}

export interface CurrentLot {
    slug: string;
    name: string;
    initials: string;
    logo_url: string | null;
    status: LotStatus;
    status_label: string;
    plan: string | null;
    role: LotRole | null;
    submitted: boolean;
    leads_badge: number;
    deals_badge: number;
    support_badge: number;
    can_costs: boolean;
}

export interface HoursDay {
    weekday: number;
    label: string;
    is_closed: boolean;
    opens_at: string | null;
    closes_at: string | null;
}

export interface LotHours {
    days: HoursDay[];
    slot_minutes: number;
    slot_capacity: number;
}

export interface LotSettings {
    slug: string;
    name: string;
    tagline: string | null;
    about: string | null;
    phone: string | null;
    whatsapp: string | null;
    email: string | null;
    logo_url: string | null;
    cover_url: string | null;
    brand_color: string | null;
    address: string | null;
    landmark: string | null;
    city: string | null;
    state: string | null;
    latitude: number | null;
    longitude: number | null;
    status: LotStatus;
    submitted: boolean;
    hours?: LotHours;
    invitations?: { id: number; contact: string; role: LotRole }[];
}

export interface CurrentLender {
    slug: string;
    name: string;
    initials: string;
    status: 'pending' | 'active' | 'suspended' | 'rejected';
    status_label: string;
    role: 'admin' | 'officer';
    new_badge: number;
    unread_badge: number;
}

export interface SharedProps {
    [key: string]: unknown;
    auth: { user: AuthUser | null };
    lots: LotSummary[];
    currentLot: CurrentLot | null;
    lenders?: { slug: string; name: string; status: string }[];
    currentLender?: CurrentLender | null;
    budget: number | null;
    unread: { notifications: number; messages: number } | null;
    regions?: { value: string; label: string }[];
    flash: { success: string | null; error: string | null };
    errors: Record<string, string>;
}

declare global {
    const route: typeof routeFn;
}

declare module 'vue' {
    interface ComponentCustomProperties {
        route: typeof routeFn;
    }
}

export interface PublicLot {
    slug: string;
    url: string;
    name: string;
    initials: string;
    tagline: string | null;
    about: string | null;
    logo_url: string | null;
    cover_url: string | null;
    brand_color: string | null;
    verified: boolean;
    rating: number | null;
    reviews_count: number;
    address: string | null;
    landmark: string | null;
    city: string | null;
    state: string | null;
    location: { lat: number; lng: number } | null;
    directions_url: string | null;
    phone: string | null;
    phone_display: string | null;
    whatsapp: string | null;
    open: { open: boolean; label: string } | null;
    hours: { days: string; hours: string }[];
    trade_ins: boolean;
}

export interface PublicReview {
    ulid: string;
    mine: boolean;
    author: string;
    rating: number;
    body: string | null;
    tags: string[];
    visit: string | null;
    date: string;
    reply: string | null;
}

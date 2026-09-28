export interface Option {
    value: string;
    label: string;
}

export interface ManagerOptions {
    sources: Option[];
    interests: Option[];
    next_steps: Option[];
    methods: Option[];
    tags: Option[];
}

export interface StockCar {
    ulid: string;
    title: string;
    price: number | null;
    price_label: string | null;
    status: string;
    orderable: boolean;
}

export interface CustomerRef {
    ulid: string;
    name: string;
    phone_display: string;
}

export interface Customer extends CustomerRef {
    phone: string;
    whatsapp: string;
    email: string | null;
    source: string;
    source_label: string;
    tags: string[];
    budget_max: number | null;
    budget_label: string | null;
    notes: string | null;
    consent_whatsapp: boolean;
    last_seen: string | null;
    has_account: boolean;
}

export interface WalkInRow {
    ulid: string;
    customer: CustomerRef | null;
    time: string;
    when: string;
    interest: string;
    interest_label: string;
    next_step: string;
    next_step_label: string;
    cars: string[];
    staff: string | null;
    notes: string | null;
}

export interface OrderRow {
    ulid: string;
    order_no: string;
    status: string;
    status_label: string;
    open: boolean;
    customer: CustomerRef | null;
    car: string | null;
    total: string;
    paid: string;
    balance: string;
    balance_minor: number;
    progress: number;
    created: string | null;
}

export interface TaskRow {
    ulid: string;
    type: string;
    type_label: string;
    due: string;
    overdue: boolean;
    note: string | null;
    assignee: string | null;
    customer: (CustomerRef & { phone: string; whatsapp: string }) | null;
}

export interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
    last_page: number;
}

export const statusBadge: Record<string, string> = {
    draft: 'bg-sand text-muted',
    deposit_paid: 'bg-blush text-clay-dark',
    fully_paid: 'bg-[#E3F1E8] text-success',
    papers_ready: 'bg-[#E3F1E8] text-success',
    delivered: 'bg-forest text-white',
    cancelled: 'bg-sand text-muted line-through',
};

export const interestBadge: Record<string, string> = {
    browsing: 'bg-sand text-muted',
    serious: 'bg-blush text-clay-dark',
    ready_to_buy: 'bg-[#E3F1E8] text-success',
};

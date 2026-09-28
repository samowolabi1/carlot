<script setup lang="ts">
import CarGlyph from '@/components/CarGlyph.vue';
import Icon from '@/components/Icon.vue';
import Logo from '@/components/Logo.vue';
import { Head } from '@inertiajs/vue3';

defineProps<{
    order: {
        order_no: string;
        status: string;
        status_label: string;
        cancelled: boolean;
        customer: string | null;
        car: string | null;
        photo: string | null;
        total: string;
        paid: string;
        balance: string;
        fully_paid: boolean;
        progress: number;
        delivered: string | null;
    };
    steps: { label: string; done: boolean }[];
    payments: { amount: string; refund: boolean; method: string; when: string; receipt_no: string | null; receipt_url: string }[];
    lot: {
        name: string;
        initials: string;
        logo_url: string | null;
        phone: string | null;
        phone_display: string | null;
        whatsapp: string | null;
        address: string;
        directions: string | null;
        url: string;
    };
    instalments: { due: string; amount: string; status: string; status_label: string }[];
    next: { due: string; amount: string; overdue: boolean } | null;
    documents: { name: string; status: string; status_label: string }[];
    poweredBy: string;
}>();
</script>

<template>
    <Head :title="`Order ${order.order_no}`" />
    <div class="min-h-dvh bg-ivory">
        <main class="mx-auto flex max-w-xl flex-col gap-[18px] px-5 py-7">
            <header class="flex items-center gap-3">
                <img v-if="lot.logo_url" :src="lot.logo_url" alt="" class="h-11 w-11 rounded-xl object-cover" />
                <span v-else class="flex h-11 w-11 items-center justify-center rounded-xl bg-forest text-[15px] font-bold text-white">{{ lot.initials }}</span>
                <div class="min-w-0">
                    <a :href="lot.url" class="block truncate text-[16px] font-bold text-ink no-underline">{{ lot.name }}</a>
                    <span class="text-[13px] text-muted">Order {{ order.order_no }}</span>
                </div>
            </header>

            <section class="card overflow-hidden">
                <div class="flex h-44 items-center justify-center bg-sand">
                    <img v-if="order.photo" :src="order.photo" alt="" class="h-full w-full object-cover" />
                    <CarGlyph v-else :width="120" />
                </div>
                <div class="flex flex-col gap-1 p-4">
                    <p v-if="order.customer" class="text-[14px] text-muted">Hi {{ order.customer }}, here is your order.</p>
                    <h1 class="text-[24px] leading-tight font-bold">{{ order.car }}</h1>
                    <p class="text-[15px] font-semibold" :class="order.cancelled ? 'text-muted' : 'text-forest'">{{ order.status_label }}<template v-if="order.delivered"> on {{ order.delivered }}</template></p>
                </div>
            </section>

            <ol v-if="!order.cancelled" class="card flex flex-col gap-0 p-4" aria-label="Progress">
                <li v-for="(s, i) in steps" :key="s.label" class="flex items-center gap-3 py-1.5">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[12px] font-bold" :class="s.done ? 'bg-forest text-white' : 'bg-sand text-muted'">
                        <Icon v-if="s.done" name="check" :size="14" :stroke-width="2.6" />
                        <template v-else>{{ i + 1 }}</template>
                    </span>
                    <span class="text-[15px]" :class="s.done ? 'font-semibold' : 'text-muted'">{{ s.label }}</span>
                </li>
            </ol>

            <section class="card p-4">
                <h2 class="mb-2 font-sans text-[16px] font-bold">Payments</h2>
                <div class="mb-3 h-2 overflow-hidden rounded-full bg-sand"><div class="h-full rounded-full bg-forest" :style="{ width: `${order.progress}%` }" /></div>
                <dl class="flex flex-col gap-1.5 text-[14px]">
                    <div class="flex justify-between"><dt class="text-muted">Price</dt><dd class="font-semibold">{{ order.total }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">Paid</dt><dd class="font-semibold">{{ order.paid }}</dd></div>
                    <div class="flex justify-between border-t border-divider pt-1.5">
                        <dt class="font-semibold">Balance</dt>
                        <dd class="font-display text-[18px] font-bold" :class="order.fully_paid ? 'text-success' : 'text-clay-dark'">{{ order.fully_paid ? 'Fully paid' : order.balance }}</dd>
                    </div>
                </dl>
                <ul v-if="payments.length" class="mt-3 divide-y divide-divider border-t border-divider">
                    <li v-for="p in payments" :key="p.receipt_no ?? p.when" class="flex items-center justify-between gap-3 py-2.5 text-[14px]">
                        <span>
                            <span class="font-semibold">{{ p.refund ? 'Refund ' : '' }}{{ p.amount }}</span>
                            <span class="block text-[13px] text-muted">{{ p.method }} · {{ p.when }}</span>
                        </span>
                        <a :href="p.receipt_url" target="_blank" rel="noopener" class="inline-flex h-11 items-center gap-1.5 font-semibold"><Icon name="download" :size="16" /> Receipt</a>
                    </li>
                </ul>
            </section>

            <section v-if="instalments.length" class="card p-4" aria-labelledby="plan-heading">
                <h2 id="plan-heading" class="mb-2 font-sans text-[16px] font-bold">Instalment plan</h2>
                <p v-if="next" class="mb-3 rounded-xl px-3 py-2.5 text-[14px]" :class="next.overdue ? 'bg-[#FDECEC] text-danger' : 'bg-cream text-clay-dark'">
                    <strong>{{ next.overdue ? 'Overdue' : 'Next' }}: {{ next.amount }}</strong> {{ next.overdue ? 'was due' : 'due' }} {{ next.due }}
                </p>
                <ul class="divide-y divide-divider text-[14px]">
                    <li v-for="i in instalments" :key="i.due" class="flex items-center justify-between gap-3 py-2">
                        <span>{{ i.due }}</span>
                        <span class="flex items-center gap-3">
                            <span class="font-semibold">{{ i.amount }}</span>
                            <span class="w-20 text-right text-[13px]" :class="i.status === 'paid' ? 'text-success' : i.status === 'overdue' ? 'text-danger' : 'text-muted'">{{ i.status_label }}</span>
                        </span>
                    </li>
                </ul>
            </section>

            <section v-if="documents.length && !order.cancelled" class="card p-4" aria-labelledby="papers-heading">
                <h2 id="papers-heading" class="mb-2 font-sans text-[16px] font-bold">Papers and handover</h2>
                <ul class="flex flex-col gap-1.5 text-[14px]">
                    <li v-for="d in documents" :key="d.name" class="flex items-center justify-between gap-3">
                        <span class="flex items-center gap-2">
                            <Icon :name="d.status === 'pending' ? 'clock' : 'check'" :size="16" :class="d.status === 'pending' ? 'text-muted' : 'text-success'" />{{ d.name }}
                        </span>
                        <span class="text-[13px] text-muted">{{ d.status_label }}</span>
                    </li>
                </ul>
            </section>

            <section class="card flex flex-col gap-3 p-4">
                <h2 class="font-sans text-[16px] font-bold">Questions about your order?</h2>
                <p v-if="lot.address" class="text-[14px] text-muted">{{ lot.address }}</p>
                <div class="flex flex-wrap gap-2">
                    <a v-if="lot.whatsapp" :href="`https://wa.me/${lot.whatsapp}?text=${encodeURIComponent(`Hi, about order ${order.order_no}`)}`" target="_blank" rel="noopener" class="btn btn-primary h-11 grow text-[14px]">
                        <Icon name="whatsapp" :size="18" /> WhatsApp {{ lot.name }}
                    </a>
                    <a v-if="lot.phone" :href="`tel:${lot.phone}`" class="btn btn-outline h-11 grow text-[14px]"><Icon name="phone" :size="18" /> Call</a>
                    <a v-if="lot.directions" :href="lot.directions" target="_blank" rel="noopener" class="btn btn-outline h-11 grow text-[14px]"><Icon name="navigate" :size="18" /> Directions</a>
                </div>
            </section>

            <a :href="poweredBy" class="card flex items-center gap-3 p-4 text-ink no-underline">
                <span class="grow">
                    <span class="block text-[15px] font-semibold">Open in LotLink</span>
                    <span class="block text-[13px] text-muted">Find cars from trusted lots near you, book test drives and keep your receipts.</span>
                </span>
                <Icon name="chevronDown" class="-rotate-90" />
            </a>

            <footer class="flex items-center justify-center gap-1.5 pb-4 text-[13px] text-muted">
                Powered by <a :href="poweredBy" class="no-underline"><Logo size="sm" /></a>
            </footer>
        </main>
    </div>
</template>

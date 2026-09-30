<script setup lang="ts">
import Icon, { type IconName } from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import { statusBadge, type Customer, type ManagerOptions, type OrderRow } from '@/components/manager/types';
import { useShared } from '@/composables/useShared';
import DealerLayout from '@/layouts/DealerLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface TimelineItem {
    kind: 'walk_in' | 'order' | 'payment' | 'task' | 'booking';
    at: string;
    title: string;
    detail: string;
    by?: string | null;
    href?: string;
}

const props = defineProps<{
    customer: Customer;
    orders: OrderRow[];
    timeline: TimelineItem[];
    stats: { visits: number; orders: number; paid: string };
    options: ManagerOptions;
}>();

const { currentLot } = useShared();
const lot = computed(() => currentLot.value!);
const editing = ref(false);

const icons: Record<TimelineItem['kind'], IconName> = { walk_in: 'walkIn', order: 'receipt', payment: 'card', task: 'phone', booking: 'calendar' };

const form = useForm({
    name: props.customer.name,
    email: props.customer.email ?? '',
    source: props.customer.source,
    tags: [...props.customer.tags],
    budget_max: props.customer.budget_max ? String(props.customer.budget_max) : '',
    notes: props.customer.notes ?? '',
    consent_whatsapp: props.customer.consent_whatsapp,
});

function save() {
    form.patch(route('dealer.manager.customers.update', [lot.value.slug, props.customer.ulid]), {
        preserveScroll: true,
        onSuccess: () => (editing.value = false),
    });
}
</script>

<template>
    <Head :title="customer.name" />
    <DealerLayout>
        <Link :href="route('dealer.manager.customers.index', lot.slug)" class="inline-flex h-11 items-center gap-1 self-start text-[14px] font-semibold no-underline">
            <Icon name="chevronLeft" :size="18" /> Customers
        </Link>

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-[30px] font-bold">{{ customer.name }}</h1>
                <p class="text-[14px] text-muted">
                    {{ customer.phone_display }} · {{ customer.source_label }}<template v-if="customer.budget_label"> · budget {{ customer.budget_label }}</template>
                </p>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    <span v-for="t in customer.tags" :key="t" class="rounded-full bg-blush px-2.5 py-0.5 text-[12px] font-semibold text-clay-dark capitalize">{{ t }}</span>
                    <span class="rounded-full px-2.5 py-0.5 text-[12px] font-semibold" :class="customer.consent_whatsapp ? 'bg-[#E3F1E8] text-success' : 'bg-sand text-muted'">
                        {{ customer.consent_whatsapp ? 'Agreed to WhatsApp' : 'No WhatsApp consent' }}
                    </span>
                    <span v-if="customer.has_account" class="rounded-full bg-forest px-2.5 py-0.5 text-[12px] font-semibold text-white">On LotLink</span>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <a :href="`tel:${customer.phone}`" class="btn btn-outline h-11 px-4 text-[14px]"><Icon name="phone" :size="18" /> Call</a>
                <a :href="`https://wa.me/${customer.whatsapp}`" target="_blank" rel="noopener" class="btn btn-outline h-11 px-4 text-[14px]"><Icon name="whatsapp" :size="18" /> WhatsApp</a>
                <Link :href="route('dealer.manager.orders.create', { lot: lot.slug, customer: customer.ulid })" class="btn btn-primary h-11 px-4 text-[14px]">New order</Link>
            </div>
        </div>

        <div class="grid grid-cols-3 gap-3">
            <div class="card p-[18px]">
                <div class="text-[13px] text-muted">Visits</div>
                <div class="font-display text-[26px] font-bold">{{ stats.visits }}</div>
            </div>
            <div class="card p-[18px]">
                <div class="text-[13px] text-muted">Orders</div>
                <div class="font-display text-[26px] font-bold">{{ stats.orders }}</div>
            </div>
            <div class="card p-[18px]">
                <div class="text-[13px] text-muted">Paid in total</div>
                <div class="font-display text-[26px] font-bold">{{ stats.paid }}</div>
            </div>
        </div>

        <div class="grid gap-5 xl:grid-cols-[1fr_380px]">
            <section class="card p-5" aria-labelledby="timeline-heading">
                <h2 id="timeline-heading" class="mb-4 font-sans text-[16px] font-bold">Timeline</h2>
                <ol class="flex flex-col">
                    <li v-for="(item, i) in timeline" :key="i" class="relative flex gap-3 pb-5 last:pb-0">
                        <span v-if="i < timeline.length - 1" class="absolute top-10 bottom-0 left-[19px] w-px bg-line" aria-hidden="true" />
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-ivory text-forest"><Icon :name="icons[item.kind]" :size="18" /></span>
                        <div class="min-w-0 pt-1">
                            <component :is="item.href ? Link : 'span'" :href="item.href" class="font-semibold text-ink" :class="item.href ? '' : 'no-underline'">{{ item.title }}</component>
                            <p v-if="item.detail" class="text-[14px] text-muted">{{ item.detail }}</p>
                            <p class="text-[12px] text-muted">{{ item.at }}<template v-if="item.by"> · {{ item.by }}</template></p>
                        </div>
                    </li>
                </ol>
            </section>

            <div class="flex flex-col gap-5">
                <section v-if="orders.length" class="card overflow-hidden" aria-labelledby="orders-heading">
                    <h2 id="orders-heading" class="border-b border-divider px-4 py-3 font-sans text-[16px] font-bold">Orders</h2>
                    <ul class="divide-y divide-divider">
                        <li v-for="o in orders" :key="o.ulid">
                            <Link :href="route('dealer.manager.orders.show', [lot.slug, o.ulid])" class="flex items-center justify-between gap-3 px-4 py-3 text-ink no-underline hover:bg-ivory">
                                <span class="min-w-0">
                                    <span class="block truncate font-semibold">{{ o.car }}</span>
                                    <span class="block text-[13px] text-muted">{{ o.order_no }} · {{ o.total }}</span>
                                </span>
                                <span class="shrink-0 rounded-full px-2.5 py-1 text-[12px] font-semibold" :class="statusBadge[o.status]">{{ o.status_label }}</span>
                            </Link>
                        </li>
                    </ul>
                </section>

                <section class="card p-5" aria-labelledby="details-heading">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 id="details-heading" class="font-sans text-[16px] font-bold">Details</h2>
                        <button v-if="!editing" type="button" class="h-11 px-2 text-[14px] font-semibold text-forest" @click="editing = true">Edit</button>
                    </div>
                    <dl v-if="!editing" class="flex flex-col gap-2 text-[14px]">
                        <div class="flex justify-between gap-3"><dt class="text-muted">Email</dt><dd>{{ customer.email ?? '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-muted">Last seen</dt><dd>{{ customer.last_seen ?? '—' }}</dd></div>
                        <div v-if="customer.notes" class="flex flex-col gap-1"><dt class="text-muted">Notes</dt><dd class="rounded-xl bg-ivory p-3 whitespace-pre-line">{{ customer.notes }}</dd></div>
                    </dl>
                    <form v-else class="flex flex-col gap-3" @submit.prevent="save">
                        <label class="field-label">Name<input v-field="'person_name'" v-model="form.name" class="field" required /><InputError :message="form.errors.name" /></label>
                        <label class="field-label">Email<input v-field="{ kind: 'email', max: 120 }" v-model="form.email" class="field" type="email" /><InputError :message="form.errors.email" /></label>
                        <label class="field-label">
                            Source
                            <select v-model="form.source" class="field">
                                <option v-for="o in options.sources" :key="o.value" :value="o.value">{{ o.label }}</option>
                            </select>
                        </label>
                        <fieldset>
                            <legend class="mb-1.5 text-[13px] font-semibold">Tags</legend>
                            <div class="flex flex-wrap gap-1.5">
                                <label v-for="t in options.tags" :key="t.value" class="flex h-10 cursor-pointer items-center rounded-full border border-line-strong px-3 text-[13px] has-[:checked]:border-forest has-[:checked]:bg-forest has-[:checked]:text-white">
                                    <input v-model="form.tags" type="checkbox" :value="t.value" class="sr-only" />{{ t.label }}
                                </label>
                            </div>
                        </fieldset>
                        <label class="field-label">Budget (₦)<input v-field="{ kind: 'money', min: 0 }" v-model="form.budget_max" class="field" inputmode="numeric" /><InputError :message="form.errors.budget_max" /></label>
                        <label class="field-label">Notes<textarea v-field="{ kind: 'text', max: 2000 }" v-model="form.notes" class="field h-24 py-2.5" /></label>
                        <label class="flex min-h-11 items-center gap-3 text-[14px]">
                            <input v-model="form.consent_whatsapp" type="checkbox" class="h-5 w-5 accent-forest" /> Agreed to WhatsApp messages
                        </label>
                        <div class="flex gap-2">
                            <button type="submit" class="btn btn-dark h-11 grow text-[14px]" :disabled="form.processing">Save</button>
                            <button type="button" class="btn btn-outline h-11 text-[14px]" @click="editing = false">Cancel</button>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </DealerLayout>
</template>

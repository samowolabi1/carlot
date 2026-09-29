<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import { useShared } from '@/composables/useShared';
import DealerLayout from '@/layouts/DealerLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';

type Import = { ulid: string; name: string; status: 'queued' | 'processing' | 'done' | 'failed'; total: number; imported: number; errors: { row: number; messages: string[] }[]; by: string | null; when: string };

const props = defineProps<{ allowed: boolean; columns: string[]; maxRows: number; imports: Import[] }>();

const { currentLot } = useShared();
const lot = computed(() => currentLot.value!);
const form = useForm<{ file: File | null }>({ file: null });
const busy = computed(() => props.imports.some((i) => i.status === 'queued' || i.status === 'processing'));
const tone = { queued: 'bg-sand text-ink', processing: 'bg-cream text-clay-dark', done: 'bg-map text-forest', failed: 'bg-[#FDECEC] text-danger' } as const;
const label = { queued: 'Waiting', processing: 'Importing…', done: 'Done', failed: 'Failed' } as const;

function submit() {
    form.post(route('dealer.vehicles.import.store', lot.value.slug), { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset() });
}

// While an import runs on the queue, refresh its row every few seconds.
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    timer = setInterval(() => busy.value && router.reload({ only: ['imports'] }), 3000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <Head title="Bulk import" />
    <DealerLayout>
        <Link :href="route('dealer.vehicles.index', lot.slug)" class="inline-flex h-11 items-center gap-1 self-start text-[14px] font-semibold no-underline"><Icon name="chevronLeft" :size="18" /> Stock</Link>
        <div>
            <h1 class="text-[30px] font-bold">Bulk import</h1>
            <p class="text-[14px] text-muted">Add many cars at once from a spreadsheet. They arrive as drafts: add photos, then publish.</p>
        </div>

        <section v-if="!allowed" class="card flex max-w-2xl flex-col gap-2 p-5">
            <span class="self-start rounded-lg bg-forest px-2 py-0.5 text-[11px] font-semibold text-white">Enterprise</span>
            <h2 class="font-sans text-[16px] font-bold">Bulk import comes with the Enterprise plan</h2>
            <p class="text-[14px] text-muted">Moving a big stock list over, or run several branches? Talk to us about Enterprise. You can still download the template to see the format.</p>
            <div class="flex flex-wrap gap-2">
                <Link :href="route('dealer.billing', lot.slug)" class="btn btn-primary h-11 no-underline">See plans</Link>
                <a :href="route('dealer.vehicles.import.template', lot.slug)" class="btn btn-outline h-11 no-underline"><Icon name="download" :size="16" /> Template</a>
            </div>
        </section>

        <div v-else class="grid max-w-5xl gap-4 lg:grid-cols-[1fr_1fr]">
            <section class="card flex flex-col gap-3 p-5" aria-labelledby="step1">
                <h2 id="step1" class="font-sans text-[15px] font-bold">1. Fill in the template</h2>
                <p class="text-[14px] text-muted">
                    One car per row, up to {{ maxRows }} rows. Make, model and year are required; the rest can be filled in later. Use naira for the price and
                    kilometres for mileage. A VIN already in your stock is skipped.
                </p>
                <p class="text-[12px] text-muted">Columns: {{ columns.join(', ') }}</p>
                <a :href="route('dealer.vehicles.import.template', lot.slug)" class="btn btn-outline h-11 self-start no-underline"><Icon name="download" :size="16" /> Download template (.xlsx)</a>
            </section>

            <form class="card flex flex-col gap-3 p-5" aria-labelledby="step2" @submit.prevent="submit">
                <h2 id="step2" class="font-sans text-[15px] font-bold">2. Upload it</h2>
                <label class="field-label">
                    Spreadsheet (.xlsx or .csv, up to 5 MB)
                    <span class="btn btn-outline h-12 cursor-pointer text-[14px]"><Icon name="upload" :size="18" /> <span class="truncate">{{ form.file ? form.file.name : 'Choose file' }}</span></span>
                    <input type="file" accept=".xlsx,.xls,.csv,text/csv" class="sr-only" @change="form.file = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                    <InputError :message="form.errors.file" />
                </label>
                <button type="submit" class="btn btn-primary h-11 self-start" :disabled="form.processing || !form.file">Import cars</button>
            </form>
        </div>

        <section v-if="imports.length" class="flex max-w-5xl flex-col gap-3" aria-labelledby="history">
            <h2 id="history" class="font-sans text-[16px] font-bold">Recent imports</h2>
            <article v-for="i in imports" :key="i.ulid" class="card flex flex-col gap-2 p-4">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <strong class="text-[15px]">{{ i.name }}</strong>
                    <span class="rounded-lg px-2 py-0.5 text-[12px] font-semibold" :class="tone[i.status]">{{ label[i.status] }}</span>
                    <span class="text-[13px] text-muted">{{ i.when }}<template v-if="i.by"> · {{ i.by }}</template></span>
                </div>
                <p v-if="i.status === 'done'" class="text-[14px]">
                    <strong>{{ i.imported }}</strong> of {{ i.total }} {{ i.total === 1 ? 'row' : 'rows' }} added as drafts.
                    <Link v-if="i.imported" :href="route('dealer.vehicles.index', { lot: lot.slug, status: 'draft' })">See drafts</Link>
                </p>
                <details v-if="i.errors.length" class="rounded-xl bg-[#FDECEC] p-3 text-[14px]" :open="i.errors.length <= 5">
                    <summary class="cursor-pointer font-semibold text-danger">{{ i.errors.filter((e) => e.row > 0).length }} {{ i.errors.filter((e) => e.row > 0).length === 1 ? 'row' : 'rows' }} to fix</summary>
                    <ul class="mt-2 flex flex-col gap-1">
                        <li v-for="e in i.errors" :key="e.row"><strong v-if="e.row">Row {{ e.row }}:</strong> {{ e.messages.join(' ') }}</li>
                    </ul>
                </details>
            </article>
        </section>
    </DealerLayout>
</template>

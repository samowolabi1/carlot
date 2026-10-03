<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { useShared } from '@/composables/useShared';
import DealerLayout from '@/layouts/DealerLayout.vue';
import InputError from '@/components/InputError.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    site: { url: string; live: boolean; tagline: string | null; brand_color: string; logo_url: string | null; initials: string };
    stickers: number;
    canEdit: boolean;
    domain: { allowed: boolean; can_manage: boolean; name: string | null; verified: boolean; txt_host: string | null; txt_value: string | null; cname: string };
}>();

const domainForm = useForm({ domain: props.domain.name ?? '' });
const saveDomain = () => domainForm.put(route('dealer.domain.store', lot.value.slug), { preserveScroll: true });
const verifyDomain = () => router.post(route('dealer.domain.verify', lot.value.slug), {}, { preserveScroll: true });
const removeDomain = () => router.delete(route('dealer.domain.destroy', lot.value.slug), { preserveScroll: true });

const { currentLot } = useShared();
const lot = computed(() => currentLot.value!);
const copied = ref(false);
const display = computed(() => props.site.url.replace(/^https?:\/\//, ''));
const whatsapp = computed(() => `https://wa.me/?text=${encodeURIComponent(`See all our cars and book a test drive: ${props.site.url}`)}`);

async function copy() {
    try {
        await navigator.clipboard.writeText(props.site.url);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
}
</script>

<template>
    <Head title="Mini-site and QR codes" />
    <DealerLayout>
        <div>
            <h1 class="text-[30px] font-bold">Mini-site and QR codes</h1>
            <p class="text-[14px] text-muted">Your own page to share anywhere, plus printables for the seller</p>
        </div>

        <p v-if="!site.live" class="rounded-xl bg-cream px-4 py-3 text-[14px] text-clay-dark" role="status">
            Your business isn't live yet, so only your team can open the page and the QR codes. They start working for buyers once we approve the seller.
        </p>

        <div class="grid gap-4 xl:grid-cols-[1fr_1fr_250px]">
            <div class="flex flex-col gap-4">
                <section class="card flex flex-col gap-3 p-5" aria-labelledby="link-heading">
                    <h2 id="link-heading" class="font-sans text-[15px] font-bold">Your link</h2>
                    <div class="flex gap-2">
                        <label class="grow">
                            <span class="sr-only">Mini-site link</span>
                            <input :value="display" readonly class="field h-11" @focus="($event.target as HTMLInputElement).select()" />
                        </label>
                        <button type="button" class="btn btn-outline h-11 shrink-0" @click="copy">
                            <Icon :name="copied ? 'check' : 'copy'" :size="16" /> {{ copied ? 'Copied' : 'Copy' }}
                        </button>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a :href="whatsapp" target="_blank" rel="noopener" class="btn h-11 border-[#25D366] bg-[#25D366] text-[#0B3D1F] no-underline hover:text-[#0B3D1F]">
                            <Icon name="whatsapp" :size="18" /> Share to WhatsApp
                        </a>
                        <button type="button" class="btn btn-outline h-11" @click="copy">Copy for your Instagram bio</button>
                    </div>
                </section>

                <section class="card flex flex-col gap-3 p-5" aria-labelledby="brand-heading">
                    <div class="flex items-center justify-between">
                        <h2 id="brand-heading" class="font-sans text-[15px] font-bold">Branding</h2>
                        <Link v-if="canEdit" :href="`${route('dealer.settings', lot.slug)}#branding`" class="inline-flex min-h-11 items-center text-[14px] font-semibold">Edit</Link>
                    </div>
                    <div class="flex items-center gap-3">
                        <img v-if="site.logo_url" :src="site.logo_url" alt="" class="h-16 w-16 shrink-0 rounded-[14px] object-cover" />
                        <span v-else class="flex h-16 w-16 shrink-0 items-center justify-center rounded-[14px] font-display text-[22px] font-bold text-white" :style="{ background: site.brand_color }">{{ site.initials }}</span>
                        <div class="flex min-w-0 flex-col gap-1 text-[14px]">
                            <span class="flex items-center gap-2"><span class="h-5 w-5 rounded-md ring-1 ring-line" :style="{ background: site.brand_color }" /> Brand colour {{ site.brand_color }}</span>
                            <span class="text-muted">{{ site.tagline ?? 'No tagline yet' }}</span>
                        </div>
                    </div>
                </section>

                <section class="card flex flex-col gap-3 p-5" :class="{ 'bg-ivory': !domain.allowed }" aria-labelledby="domain-heading">
                    <div class="flex items-center justify-between">
                        <h2 id="domain-heading" class="font-sans text-[15px] font-bold">Custom domain</h2>
                        <span v-if="!domain.allowed" class="rounded-lg bg-forest px-2 py-0.5 text-[11px] font-semibold text-white">Enterprise</span>
                        <span v-else-if="domain.verified" class="rounded-lg bg-map px-2 py-0.5 text-[12px] font-semibold text-forest">Live</span>
                    </div>
                    <p v-if="!domain.allowed" class="text-[13px] text-muted">Use your own address, like primemotors.ng, instead of the CarYard link. Available on Enterprise.</p>
                    <template v-else-if="domain.can_manage">
                        <form class="flex gap-2" @submit.prevent="saveDomain">
                            <label class="grow">
                                <span class="sr-only">Your domain</span>
                                <input v-field="{ kind: 'text', max: 190 }" v-model="domainForm.domain" class="field h-11" placeholder="cars.primemotors.ng" autocomplete="off" />
                            </label>
                            <button type="submit" class="btn btn-outline h-11 shrink-0" :disabled="domainForm.processing">Save</button>
                        </form>
                        <InputError :message="domainForm.errors.domain" />
                        <div v-if="domain.name && !domain.verified" class="flex flex-col gap-2 rounded-xl bg-ivory p-3 text-[13px]">
                            <p>At your domain provider, add these two records:</p>
                            <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 break-all">
                                <dt class="font-semibold">CNAME</dt><dd><code>{{ domain.name }}</code> → <code>{{ domain.cname }}</code></dd>
                                <dt class="font-semibold">TXT</dt><dd><code>{{ domain.txt_host }}</code> = <code>{{ domain.txt_value }}</code></dd>
                            </dl>
                            <button type="button" class="btn btn-primary h-11 self-start" @click="verifyDomain">Check</button>
                        </div>
                        <p v-else-if="domain.verified" class="text-[13px] text-muted"><a :href="`https://${domain.name}`" target="_blank" rel="noopener">{{ domain.name }}</a> opens your mini-site.</p>
                        <button v-if="domain.name" type="button" class="min-h-11 self-start text-[13px] font-semibold text-muted hover:text-danger" @click="removeDomain">Remove domain</button>
                    </template>
                    <p v-else class="text-[13px] text-muted">{{ domain.name ? `${domain.name}${domain.verified ? ' is live' : ' is being set up'}.` : 'The seller can set this up.' }}</p>
                </section>
            </div>

            <div class="flex flex-col gap-4">
                <section class="card flex flex-col gap-3 p-5" aria-labelledby="poster-heading">
                    <h2 id="poster-heading" class="font-sans text-[15px] font-bold">Seller poster</h2>
                    <div class="flex gap-4">
                        <div class="flex h-[170px] w-[120px] shrink-0 flex-col items-center gap-2 rounded-lg border border-line bg-white p-2.5" aria-hidden="true">
                            <span class="h-5 w-full rounded" :style="{ background: site.brand_color }" />
                            <span class="font-display text-[11px] font-bold">{{ lot.name }}</span>
                            <Icon name="qr" :size="64" :stroke-width="1.4" />
                            <span class="text-center text-[8px] leading-tight">Scan to see every car and book a test drive</span>
                        </div>
                        <div class="flex flex-col justify-center gap-2">
                            <p class="text-[13px] text-[#4A4D53]">For the gate, office window and flyers. Opens your mini-site; scans count in Analytics.</p>
                            <a :href="route('dealer.qr.poster', { lot: lot.slug, size: 'a4' })" target="_blank" class="btn btn-primary h-11 no-underline">Download A4 PDF</a>
                            <a :href="route('dealer.qr.poster', { lot: lot.slug, size: 'a3' })" target="_blank" class="btn btn-outline h-11 no-underline">Download A3 PDF</a>
                        </div>
                    </div>
                </section>

                <section class="card flex flex-col gap-3 p-5" aria-labelledby="stickers-heading">
                    <h2 id="stickers-heading" class="font-sans text-[15px] font-bold">Windscreen stickers</h2>
                    <p class="text-[13px] text-[#4A4D53]">One QR per car. Walk-in buyers scan to save, share or book. Scans show up in Analytics as "QR code".</p>
                    <div class="grid grid-cols-4 gap-2" aria-hidden="true">
                        <span v-for="n in 4" :key="n" class="flex h-14 items-center justify-center rounded-md border border-dashed border-line-strong"><Icon name="qr" :size="32" :stroke-width="1.4" /></span>
                    </div>
                    <a v-if="stickers" :href="route('dealer.qr.stickers', lot.slug)" target="_blank" class="btn btn-outline h-11 self-start no-underline">
                        Print stickers for {{ stickers }} {{ stickers === 1 ? 'car' : 'cars' }}
                    </a>
                    <p v-else class="text-[13px] text-muted">List a car to print its sticker.</p>
                </section>
            </div>

            <div class="hidden flex-col items-center gap-2 xl:flex">
                <span class="text-[12px] font-semibold text-muted">Preview</span>
                <div class="h-[470px] w-[230px] overflow-hidden rounded-[28px] border-[6px] border-ink bg-ivory">
                    <iframe :src="site.url" title="Mini-site preview" class="h-[916px] w-[448px] origin-top-left scale-50 border-0" loading="lazy" tabindex="-1" />
                </div>
                <a :href="site.url" target="_blank" rel="noopener" class="text-[13px] font-semibold">Open full page</a>
            </div>
        </div>
    </DealerLayout>
</template>

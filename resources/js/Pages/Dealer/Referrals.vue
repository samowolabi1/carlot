<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import DealerLayout from '@/layouts/DealerLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    code: string;
    link: string;
    whatsapp: string;
    months: number;
    referrals: { lot: string; joined: string | null; status: string; rewarded: boolean }[];
}>();

const copied = ref(false);
async function copy() {
    await navigator.clipboard?.writeText(props.link);
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
}
</script>

<template>
    <Head title="Refer a seller" />
    <DealerLayout>
        <div>
            <h1 class="text-[30px] font-bold">Refer a seller</h1>
            <p class="text-[14px] text-muted">Know another seller? When they join with your link and choose a plan, you get {{ months }} free month{{ months === 1 ? '' : 's' }}.</p>
        </div>

        <section class="card flex max-w-2xl flex-col gap-4 p-5">
            <div class="flex flex-col gap-1">
                <span class="text-[13px] text-muted">Your code</span>
                <span class="font-display text-[28px] font-bold tracking-widest">{{ code }}</span>
            </div>
            <label class="field-label">
                Your link
                <input :value="link" readonly class="field text-[14px]" @focus="($event.target as HTMLInputElement).select()" />
            </label>
            <div class="flex flex-wrap gap-2">
                <a :href="whatsapp" target="_blank" rel="noopener" class="btn btn-primary h-11 text-[14px]"><Icon name="whatsapp" :size="18" /> Share on WhatsApp</a>
                <button type="button" class="btn btn-outline h-11 text-[14px]" @click="copy"><Icon name="copy" :size="18" /> {{ copied ? 'Copied' : 'Copy link' }}</button>
            </div>
        </section>

        <section class="card max-w-2xl overflow-hidden" aria-labelledby="joined-heading">
            <h2 id="joined-heading" class="border-b border-divider px-4 py-3 font-sans text-[16px] font-bold">Sellers that joined</h2>
            <p v-if="referrals.length === 0" class="px-4 py-6 text-[14px] text-muted">No one yet. Share your link with a seller you know.</p>
            <ul v-else class="divide-y divide-divider">
                <li v-for="(r, i) in referrals" :key="i" class="flex items-center justify-between gap-3 px-4 py-3 text-[14px]">
                    <span class="flex flex-col"><span class="font-semibold">{{ r.lot }}</span><span class="text-[13px] text-muted">Joined {{ r.joined }}</span></span>
                    <span class="text-right text-[13px]" :class="r.rewarded ? 'font-semibold text-success' : 'text-muted'">{{ r.status }}</span>
                </li>
            </ul>
        </section>
    </DealerLayout>
</template>

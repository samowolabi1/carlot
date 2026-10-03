<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import ReportButton from '@/components/trust/ReportButton.vue';
import { useShared } from '@/composables/useShared';
import DealerLayout from '@/layouts/DealerLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type ReviewRow = {
    ulid: string;
    author: string;
    rating: number;
    tags: string[];
    body: string | null;
    visit: string | null;
    date: string;
    edited: boolean;
    hidden: boolean;
    reply: string | null;
    reply_date: string | null;
};

const props = defineProps<{
    summary: { rating: number | null; count: number; shown: boolean; min: number; bars: { rating: number; count: number }[] };
    reviews: { data: ReviewRow[]; links: { url: string | null; label: string; active: boolean }[]; last_page: number };
    canReply: boolean;
}>();

const { currentLot } = useShared();
const lot = computed(() => currentLot.value!);
const replying = ref<string | null>(null);
const form = useForm({ reply: '' });
const maxBar = computed(() => Math.max(1, ...props.summary.bars.map((b) => b.count)));

function send(review: ReviewRow) {
    form.post(route('dealer.reviews.reply', [lot.value.slug, review.ulid]), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            replying.value = null;
        },
    });
}
</script>

<template>
    <Head title="Reviews" />
    <DealerLayout>
        <div>
            <h1 class="text-[30px] font-bold">Reviews</h1>
            <p class="text-[14px] text-muted">Only buyers with a completed visit can review, so every review is from someone who came to the lot. You can reply once to each.</p>
        </div>

        <section class="card flex flex-col gap-5 p-5 sm:flex-row sm:items-center" aria-label="Rating summary">
            <div class="flex shrink-0 flex-col">
                <span class="font-display text-[40px] leading-none font-bold">{{ summary.rating ?? '—' }}<span v-if="summary.rating" class="text-[18px] text-muted"> / 5</span></span>
                <span class="text-[14px] text-muted">{{ summary.count }} {{ summary.count === 1 ? 'review' : 'reviews' }}</span>
                <span v-if="!summary.shown" class="mt-1 max-w-[220px] text-[13px] text-muted">Buyers see your rating once you have {{ summary.min }} reviews.</span>
            </div>
            <ul class="flex grow flex-col gap-1.5">
                <li v-for="bar in summary.bars" :key="bar.rating" class="flex items-center gap-2 text-[13px]">
                    <span class="w-10 shrink-0 text-muted">{{ bar.rating }} star</span>
                    <span class="h-2.5 grow overflow-hidden rounded-full bg-sand">
                        <span class="block h-full rounded-full bg-clay" :style="{ width: `${(bar.count / maxBar) * 100}%` }" />
                    </span>
                    <span class="w-6 shrink-0 text-right">{{ bar.count }}</span>
                </li>
            </ul>
        </section>

        <div v-if="!reviews.data.length" class="card flex flex-col items-center gap-2 p-10 text-center">
            <Icon name="star" :size="28" class="text-muted" />
            <p class="max-w-sm text-[15px] text-muted">No reviews yet. Buyers are asked for one 2 hours after you mark their visit complete in the calendar.</p>
        </div>

        <ul v-else class="flex flex-col gap-3">
            <li v-for="r in reviews.data" :key="r.ulid" class="card flex flex-col gap-2 p-4" :class="{ 'opacity-70': r.hidden }">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span class="flex" :aria-label="`${r.rating} out of 5 stars`">
                        <Icon v-for="n in 5" :key="n" name="star" :size="16" :class="n <= r.rating ? 'fill-clay text-clay' : 'text-line-strong'" />
                    </span>
                    <strong class="text-[15px]">{{ r.author }}</strong>
                    <span class="text-[13px] text-muted">{{ r.visit }} · {{ r.date }}<template v-if="r.edited"> · edited</template></span>
                    <span v-if="r.hidden" class="rounded-lg bg-cream px-2 py-0.5 text-[12px] font-semibold text-clay-dark">Hidden while LotLink reviews a report</span>
                </div>
                <p v-if="r.body" class="text-[15px] whitespace-pre-line">{{ r.body }}</p>
                <ul v-if="r.tags.length" class="flex flex-wrap gap-1.5">
                    <li v-for="t in r.tags" :key="t" class="rounded-full bg-map px-2.5 py-1 text-[12px] font-semibold text-forest">{{ t }}</li>
                </ul>

                <div v-if="r.reply" class="mt-1 rounded-xl bg-ivory p-3 text-[14px]">
                    <strong>Your reply</strong> <span class="text-muted">· {{ r.reply_date }}</span>
                    <p class="mt-1 whitespace-pre-line">{{ r.reply }}</p>
                </div>
                <template v-else-if="canReply">
                    <form v-if="replying === r.ulid" class="flex flex-col gap-2" @submit.prevent="send(r)">
                        <label class="field-label">
                            Reply publicly (once)
                            <textarea v-field="{ kind: 'text', max: 1000 }" v-model="form.reply" rows="3" class="field h-auto py-2" placeholder="Thank the buyer, or explain what you've done about a problem." />
                            <InputError :message="form.errors.reply" />
                        </label>
                        <div class="flex gap-2">
                            <button type="submit" class="btn btn-primary h-11" :disabled="form.processing || !form.reply.trim()">Post reply</button>
                            <button type="button" class="btn btn-outline h-11" @click="replying = null">Cancel</button>
                        </div>
                    </form>
                    <button v-else type="button" class="inline-flex min-h-11 items-center self-start text-[14px] font-semibold text-clay" @click="replying = r.ulid">Reply</button>
                </template>
                <ReportButton v-if="!r.hidden" kind="review" :id="r.ulid" label="Report to LotLink" class="self-end" />
            </li>
        </ul>

        <nav v-if="reviews.last_page > 1" aria-label="Pages" class="flex flex-wrap gap-1">
            <template v-for="link in reviews.links" :key="link.label">
                <Link v-if="link.url" :href="link.url" preserve-scroll class="flex h-11 min-w-11 items-center justify-center rounded-lg px-2.5 text-[14px] no-underline" :class="link.active ? 'bg-forest text-white' : 'text-ink hover:bg-white'"><span v-html="link.label" /></Link>
            </template>
        </nav>
    </DealerLayout>
</template>

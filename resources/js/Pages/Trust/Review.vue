<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    lot: { name: string; url: string };
    visit: string;
    reviewable: boolean;
    review: { rating: number; tags: string[]; body: string | null; editable: boolean; editable_until: string; hidden: boolean; reply: string | null } | null;
    tags: { value: string; label: string }[];
    author: string;
    action: string;
    back: string;
}>();

const form = useForm({ rating: props.review?.rating ?? 0, tags: [...(props.review?.tags ?? [])], body: props.review?.body ?? '' });
const hover = ref(0);
const locked = computed(() => !props.reviewable || (props.review !== null && !props.review.editable));
const words = ['', 'Poor', 'Not great', 'OK', 'Good', 'Excellent'];

function toggle(tag: string) {
    form.tags = form.tags.includes(tag) ? form.tags.filter((t) => t !== tag) : [...form.tags, tag];
}

function submit() {
    form.post(props.action, { preserveScroll: true });
}
</script>

<template>
    <Head title="Review your visit" />
    <div class="mx-auto flex min-h-dvh w-full max-w-lg flex-col gap-5 bg-ivory px-5 pt-5 pb-8">
        <Link :href="back" aria-label="Close" class="-ml-2 flex h-11 w-11 items-center justify-center text-ink"><Icon name="close" :size="22" :stroke-width="2" /></Link>

        <div class="flex flex-col gap-1.5">
            <h1 class="text-[28px] leading-tight font-bold">How was your visit to {{ lot.name }}?</h1>
            <p class="text-[14px] text-muted">{{ visit }}</p>
        </div>

        <p v-if="!reviewable" class="rounded-xl bg-cream p-4 text-[14px] text-clay-dark" role="status">You can review this visit once it has happened and the seller has marked it complete.</p>
        <p v-else-if="review?.hidden" class="rounded-xl bg-cream p-4 text-[14px] text-clay-dark" role="status">Your review was reported and is hidden while our team takes a look.</p>
        <p v-else-if="review && !review.editable" class="rounded-xl bg-map p-4 text-[14px] text-forest" role="status">Thanks for your review. Reviews can be changed for 14 days after posting.</p>

        <form class="flex grow flex-col gap-5" @submit.prevent="submit">
            <fieldset :disabled="locked" class="flex flex-col gap-5">
                <div>
                    <div role="radiogroup" aria-label="Rating" class="-ml-2 flex gap-1" @mouseleave="hover = 0">
                        <label
                            v-for="n in 5"
                            :key="n"
                            class="flex h-[52px] w-[52px] cursor-pointer items-center justify-center rounded-xl focus-within:ring-2 focus-within:ring-clay"
                            @mouseenter="hover = n"
                        >
                            <input v-model="form.rating" type="radio" name="rating" :value="n" class="sr-only" :aria-label="`${n} ${n === 1 ? 'star' : 'stars'}`" />
                            <svg width="36" height="36" viewBox="0 0 24 24" aria-hidden="true" :class="n <= (hover || form.rating) ? 'fill-clay' : 'fill-line'">
                                <path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z" />
                            </svg>
                        </label>
                    </div>
                    <p class="h-5 text-[14px] font-semibold text-muted" aria-live="polite">{{ words[hover || form.rating] }}</p>
                    <InputError :message="form.errors.rating" />
                </div>

                <div class="flex flex-col gap-2.5">
                    <span id="went-well" class="text-[15px] font-semibold">What went well?</span>
                    <div class="flex flex-wrap gap-2" role="group" aria-labelledby="went-well">
                        <button
                            v-for="tag in tags"
                            :key="tag.value"
                            type="button"
                            class="h-10 rounded-full border px-3.5 text-[14px] font-medium"
                            :class="form.tags.includes(tag.value) ? 'border-forest bg-forest text-white' : 'border-line bg-white text-ink'"
                            :aria-pressed="form.tags.includes(tag.value)"
                            @click="toggle(tag.value)"
                        >
                            {{ tag.label }}
                        </button>
                    </div>
                </div>

                <label class="flex flex-col gap-1.5 text-[15px] font-semibold">
                    Tell others about it
                    <textarea v-field="{ kind: 'text', max: 1000 }" v-model="form.body" rows="4" class="field h-auto py-3 text-[15px] font-normal" placeholder="What was the car and the seller like?" />
                    <InputError :message="form.errors.body" />
                </label>
            </fieldset>

            <div v-if="review?.reply" class="rounded-xl bg-white p-4 text-[14px] ring-1 ring-line">
                <strong>Reply from {{ lot.name }}</strong>
                <p class="mt-1 whitespace-pre-line">{{ review.reply }}</p>
            </div>

            <p class="text-[12px] text-muted">
                Only customers with a completed visit can review, so ratings stay genuine. Your name is shown as {{ author.endsWith('.') ? author : `${author}.` }}
                <template v-if="review?.editable"> You can change this review until {{ review.editable_until }}.</template>
            </p>

            <button v-if="!locked" type="submit" class="btn btn-primary mt-auto h-[52px] w-full rounded-[14px]" :disabled="form.processing || !form.rating">
                {{ review ? 'Update review' : 'Post review' }}
            </button>
            <Link v-else :href="lot.url" class="btn btn-outline mt-auto h-[52px] w-full rounded-[14px]">See {{ lot.name }}</Link>
        </form>
    </div>
</template>

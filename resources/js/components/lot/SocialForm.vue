<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { router } from '@inertiajs/vue3';

export type SocialState = {
    accounts: { ulid: string; provider: 'facebook' | 'instagram'; label: string; name: string; auto_post: boolean; error: string | null; expired: boolean }[];
    posts: { key: string; account: string; vehicle: string; car: string; provider: string; status: 'queued' | 'posted' | 'failed'; error: string | null; when: string }[];
    demo: boolean;
};

const props = defineProps<{ lotSlug: string; social: SocialState }>();

const toggle = (a: SocialState['accounts'][number]) => router.patch(route('dealer.social.update', [props.lotSlug, a.ulid]), { auto_post: !a.auto_post }, { preserveScroll: true });
const disconnect = (a: SocialState['accounts'][number]) => router.delete(route('dealer.social.destroy', [props.lotSlug, a.ulid]), { preserveScroll: true });
const retry = (p: SocialState['posts'][number]) => router.post(route('dealer.social.retry', [props.lotSlug, p.account, p.vehicle]), {}, { preserveScroll: true });
const tone = { queued: 'bg-sand text-ink', posted: 'bg-map text-forest', failed: 'bg-[#FDECEC] text-danger' } as const;
</script>

<template>
    <div class="flex flex-col gap-4">
        <div>
            <h2 class="font-sans text-[16px] font-bold">Facebook and Instagram</h2>
            <p class="text-[14px] text-muted">Connect your Facebook Page (and the Instagram Business account linked to it) and every new car you publish is posted with its best photo, price and a link to book.</p>
        </div>

        <p v-if="social.demo" class="rounded-xl bg-cream px-4 py-3 text-[13px] text-clay-dark">Demo mode: connecting adds a sample Page and posts are written to the log. Set <code>SOCIAL_DRIVER=meta</code> with a Meta app to post for real.</p>

        <ul v-if="social.accounts.length" class="flex flex-col gap-2">
            <li v-for="a in social.accounts" :key="a.ulid" class="flex flex-wrap items-center gap-3 rounded-xl border border-line p-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-white" :class="a.provider === 'facebook' ? 'bg-[#1877F2]' : 'bg-[#C13584]'">
                    <Icon name="share" :size="18" />
                </span>
                <span class="flex min-w-0 grow flex-col">
                    <strong class="truncate text-[15px]">{{ a.name }}</strong>
                    <span class="text-[13px]" :class="a.error || a.expired ? 'text-danger' : 'text-muted'">{{ a.expired ? 'Connection expired: connect again' : a.error ? `Last post failed: ${a.error}` : a.label }}</span>
                </span>
                <label class="flex min-h-11 cursor-pointer items-center gap-2 text-[14px]">
                    <input type="checkbox" class="h-5 w-5 accent-forest" :checked="a.auto_post" @change="toggle(a)" /> Auto-post new cars
                </label>
                <button type="button" class="min-h-11 text-[14px] font-semibold text-muted hover:text-danger" @click="disconnect(a)">Disconnect</button>
            </li>
        </ul>

        <a :href="route('dealer.social.connect', lotSlug)" class="btn self-start border-[#1877F2] bg-[#1877F2] text-white no-underline hover:bg-[#1466d0] hover:text-white">
            {{ social.accounts.length ? 'Reconnect Facebook' : 'Connect Facebook and Instagram' }}
        </a>

        <section v-if="social.posts.length" aria-labelledby="posts-heading" class="flex flex-col gap-2">
            <h3 id="posts-heading" class="font-sans text-[15px] font-bold">Recent posts</h3>
            <ul class="divide-y divide-divider rounded-xl border border-line">
                <li v-for="p in social.posts" :key="p.key" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2.5 text-[14px]">
                    <span class="grow">{{ p.car }} <span class="text-muted">· {{ p.provider === 'facebook' ? 'Facebook' : 'Instagram' }} · {{ p.when }}</span></span>
                    <span class="rounded-lg px-2 py-0.5 text-[12px] font-semibold capitalize" :class="tone[p.status]">{{ p.status }}</span>
                    <button v-if="p.status === 'failed'" type="button" class="min-h-11 font-semibold text-clay" @click="retry(p)">Try again</button>
                    <span v-if="p.error" class="w-full text-[12px] text-danger">{{ p.error }}</span>
                </li>
            </ul>
        </section>
    </div>
</template>

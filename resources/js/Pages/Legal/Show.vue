<script setup lang="ts">
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    document: string;
    title: string;
    version: string;
    effective: string;
    html: string;
    sections: { id: string; title: string }[];
    others: { key: string; title: string }[];
}>();
</script>

<template>
    <Head :title="title" />
    <CustomerLayout>
        <div class="mx-auto grid max-w-5xl gap-8 px-5 pt-6 pb-28 md:pb-16 lg:grid-cols-[220px_minmax(0,1fr)]">
            <aside class="order-2 flex flex-col gap-4 text-[14px] lg:sticky lg:top-6 lg:order-1 lg:self-start">
                <nav v-if="sections.length" aria-label="On this page" class="flex flex-col gap-0.5">
                    <span class="pb-1 text-[12px] font-semibold tracking-wide text-muted uppercase">On this page</span>
                    <a v-for="s in sections" :key="s.id" :href="`#${s.id}`" class="py-1 text-ink no-underline hover:text-clay">{{ s.title }}</a>
                </nav>
                <nav aria-label="Other documents" class="flex flex-col gap-0.5 border-t border-line pt-3">
                    <Link v-for="o in others" :key="o.key" :href="route('legal.show', o.key)" class="py-1 font-semibold">{{ o.title }}</Link>
                </nav>
            </aside>
            <article class="order-1 lg:order-2">
                <h1 class="text-[30px] leading-tight font-bold">{{ title }}</h1>
                <p class="mt-1 text-[14px] text-muted">Effective {{ effective }} · version {{ version }}</p>
                <!-- Rendered on the server from our own Markdown with raw HTML stripped (LegalDocuments::render). -->
                <div class="legal mt-6" v-html="html" />
            </article>
        </div>
    </CustomerLayout>
</template>

<style scoped>
.legal :deep(h2) {
    margin: 2rem 0 0.6rem;
    font-size: 20px;
    font-weight: 700;
    scroll-margin-top: 1rem;
}
.legal :deep(p),
.legal :deep(li) {
    font-size: 15px;
    line-height: 1.65;
    color: #33363b;
}
.legal :deep(p) {
    margin: 0.6rem 0;
}
.legal :deep(ul) {
    margin: 0.5rem 0 0.8rem;
    padding-left: 1.25rem;
    list-style: disc;
}
.legal :deep(li) {
    margin: 0.25rem 0;
}
.legal :deep(table) {
    width: 100%;
    margin: 0.8rem 0;
    border-collapse: collapse;
    font-size: 14px;
}
.legal :deep(th),
.legal :deep(td) {
    border: 1px solid var(--color-line, #e3dfd6);
    padding: 0.5rem 0.6rem;
    text-align: left;
    vertical-align: top;
}
.legal :deep(th) {
    background: var(--color-sand, #efebe3);
}
.legal :deep(a) {
    font-weight: 600;
}
</style>

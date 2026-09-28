<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    types: { type: string; label: string }[];
    preferences: Record<string, { phone: boolean; mail: boolean }>;
    hasEmail: boolean;
}>();

const form = useForm({ preferences: JSON.parse(JSON.stringify(props.preferences)) as Record<string, { phone: boolean; mail: boolean }> });
</script>

<template>
    <Head title="Notification settings" />
    <CustomerLayout active="account">
        <div class="mx-auto flex max-w-xl flex-col gap-4 px-5 pt-4 pb-28 md:pt-8">
            <div class="flex items-center gap-2">
                <Link :href="route('notifications')" aria-label="Back" class="-ml-3 flex h-11 w-11 items-center justify-center text-ink"><Icon name="chevronLeft" :size="22" :stroke-width="2" /></Link>
                <h1 class="text-[22px] font-bold">Notification settings</h1>
            </div>
            <p class="text-[14px] text-muted">Everything also shows in your notifications here. Choose where else you hear about it. Sign-in codes and receipts always come by WhatsApp or SMS.</p>
            <form class="card overflow-hidden" @submit.prevent="form.put(route('notifications.update'), { preserveScroll: true })">
                <table class="w-full text-[14px]">
                    <thead class="text-left text-[12px] text-muted">
                        <tr><th class="px-4 py-2 font-semibold">Notification</th><th class="w-24 py-2 text-center font-semibold">WhatsApp</th><th class="w-20 py-2 pr-4 text-center font-semibold">Email</th></tr>
                    </thead>
                    <tbody class="divide-y divide-divider">
                        <tr v-for="t in types" :key="t.type">
                            <td class="px-4 py-3">{{ t.label }}</td>
                            <td class="text-center">
                                <label class="inline-flex h-11 w-11 cursor-pointer items-center justify-center"><span class="sr-only">{{ t.label }} by WhatsApp</span><input v-model="form.preferences[t.type].phone" type="checkbox" class="h-5 w-5 accent-forest" /></label>
                            </td>
                            <td class="pr-4 text-center">
                                <label class="inline-flex h-11 w-11 cursor-pointer items-center justify-center" :class="hasEmail ? '' : 'opacity-40'"><span class="sr-only">{{ t.label }} by email</span><input v-model="form.preferences[t.type].mail" type="checkbox" class="h-5 w-5 accent-forest" :disabled="!hasEmail" /></label>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div class="flex items-center gap-3 border-t border-divider p-4">
                    <button type="submit" class="btn btn-dark h-11 text-[14px]" :disabled="form.processing">Save</button>
                    <span v-if="!hasEmail" class="text-[13px] text-muted">Add an email to your account to get emails.</span>
                </div>
            </form>
        </div>
    </CustomerLayout>
</template>

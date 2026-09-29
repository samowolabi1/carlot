<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { usePush } from '@/composables/usePush';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

type Channels = { phone: boolean; mail: boolean; push: boolean };

const props = defineProps<{
    types: { type: string; label: string }[];
    preferences: Record<string, Channels>;
    hasEmail: boolean;
    push: { key: string | null; devices: { id: string; device: string; since: string; current: boolean }[] };
}>();

const form = useForm({ preferences: JSON.parse(JSON.stringify(props.preferences)) as Record<string, Channels> });
const device = usePush(props.push.key);
const hasDevices = computed(() => props.push.devices.length > 0 || device.state.value === 'on');

async function toggle() {
    await (device.state.value === 'on' ? device.disable() : device.enable());
    router.reload({ only: ['push'] });
}

const status = computed(
    () =>
        ({
            checking: 'Checking this device…',
            'not-configured': 'Push notifications aren\'t switched on for LotLink yet.',
            unsupported: 'This browser can\'t get push notifications. Try Chrome, Edge, Firefox or Safari over a secure (https) connection.',
            'needs-install': 'On iPhone, add LotLink to your Home Screen first (Share → Add to Home Screen), open it from there, then turn this on.',
            denied: 'Notifications are blocked for LotLink in this browser. Allow them in the site settings (the icon next to the address), then come back.',
            off: 'Get alerts on this device the moment something happens, even when LotLink is closed. Free, and no data used when nothing happens.',
            on: 'On for this device.',
        })[device.state.value],
);
</script>

<template>
    <Head title="Notification settings" />
    <CustomerLayout active="account">
        <div class="mx-auto flex max-w-xl flex-col gap-4 px-5 pt-4 pb-28 md:pt-8">
            <div class="flex items-center gap-2">
                <Link :href="route('notifications')" aria-label="Back" class="-ml-3 flex h-11 w-11 items-center justify-center text-ink"><Icon name="chevronLeft" :size="22" :stroke-width="2" /></Link>
                <h1 class="text-[22px] font-bold">Notification settings</h1>
            </div>

            <section class="card flex flex-col gap-3 p-4" aria-labelledby="push-heading">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-map text-forest"><Icon name="bell" :size="20" /></span>
                    <div class="flex grow flex-col gap-1">
                        <h2 id="push-heading" class="font-sans text-[16px] font-bold">Push notifications on this device</h2>
                        <p class="text-[14px] text-muted" role="status">{{ status }}</p>
                    </div>
                </div>
                <p v-if="device.error.value" class="text-[14px] text-danger">{{ device.error.value }}</p>
                <div v-if="device.state.value === 'off' || device.state.value === 'on'" class="flex flex-wrap gap-2">
                    <button type="button" class="btn h-11 text-[14px]" :class="device.state.value === 'on' ? 'btn-outline' : 'btn-primary'" :disabled="device.busy.value" @click="toggle">
                        {{ device.busy.value ? 'One moment…' : device.state.value === 'on' ? 'Turn off on this device' : 'Turn on' }}
                    </button>
                    <button
                        v-if="device.state.value === 'on'"
                        type="button"
                        class="btn btn-outline h-11 text-[14px]"
                        @click="router.post(route('push.test'), {}, { preserveScroll: true })"
                    >
                        Send a test
                    </button>
                </div>

                <ul v-if="push.devices.length" class="flex flex-col divide-y divide-divider border-t border-divider text-[14px]">
                    <li v-for="d in push.devices" :key="d.id" class="flex min-h-11 items-center justify-between gap-3 py-2">
                        <span>
                            {{ d.device }}<span v-if="d.current" class="text-muted"> · this device</span>
                            <span class="block text-[13px] text-muted">Since {{ d.since }}</span>
                        </span>
                        <button
                            v-if="!d.current"
                            type="button"
                            class="h-11 px-2 text-[14px] font-semibold text-danger"
                            @click="router.delete(route('push.forget', d.id), { preserveScroll: true })"
                        >
                            Remove
                        </button>
                    </li>
                </ul>
            </section>

            <p class="text-[14px] text-muted">Everything also shows in your notifications here. Choose where else you hear about it. Sign-in codes and receipts always come by WhatsApp or SMS.</p>
            <form class="card overflow-hidden" @submit.prevent="form.put(route('notifications.update'), { preserveScroll: true })">
                <table class="w-full text-[14px]">
                    <thead class="text-left text-[12px] text-muted">
                        <tr>
                            <th class="px-4 py-2 font-semibold">Notification</th>
                            <th class="w-20 py-2 text-center font-semibold">WhatsApp</th>
                            <th class="w-16 py-2 text-center font-semibold">Email</th>
                            <th class="w-16 py-2 pr-4 text-center font-semibold">Push</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-divider">
                        <tr v-for="t in types" :key="t.type">
                            <td class="px-4 py-3">{{ t.label }}</td>
                            <td class="text-center">
                                <label class="inline-flex h-11 w-11 cursor-pointer items-center justify-center"><span class="sr-only">{{ t.label }} by WhatsApp</span><input v-model="form.preferences[t.type].phone" type="checkbox" class="h-5 w-5 accent-forest" /></label>
                            </td>
                            <td class="text-center">
                                <label class="inline-flex h-11 w-11 cursor-pointer items-center justify-center" :class="hasEmail ? '' : 'opacity-40'"><span class="sr-only">{{ t.label }} by email</span><input v-model="form.preferences[t.type].mail" type="checkbox" class="h-5 w-5 accent-forest" :disabled="!hasEmail" /></label>
                            </td>
                            <td class="pr-4 text-center">
                                <label class="inline-flex h-11 w-11 cursor-pointer items-center justify-center" :class="hasDevices ? '' : 'opacity-40'"><span class="sr-only">{{ t.label }} by push</span><input v-model="form.preferences[t.type].push" type="checkbox" class="h-5 w-5 accent-forest" :disabled="!hasDevices" /></label>
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

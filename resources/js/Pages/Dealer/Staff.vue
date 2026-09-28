<script setup lang="ts">
import InviteForm from '@/components/lot/InviteForm.vue';
import { useShared } from '@/composables/useShared';
import DealerLayout from '@/layouts/DealerLayout.vue';
import type { LotRole } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineProps<{
    members: { ulid: string; name: string | null; phone: string; role: LotRole; is_me: boolean }[];
    invitations: { id: number; contact: string; role: LotRole; channel: string; expires_at: string }[];
    seats: { used: number; limit: number | null; plan: string | null };
    canManage: boolean;
}>();

const { currentLot } = useShared();
const slug = computed(() => currentLot.value!.slug);
const showInvite = ref(false);

const initials = (name: string | null) =>
    (name ?? '?')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase())
        .join('');

function changeRole(ulid: string, role: string) {
    router.patch(route('dealer.staff.update', [slug.value, ulid]), { role }, { preserveScroll: true });
}

function remove(ulid: string, name: string | null) {
    if (confirm(`Remove ${name ?? 'this person'} from your team?`)) {
        router.delete(route('dealer.staff.destroy', [slug.value, ulid]), { preserveScroll: true });
    }
}

const roles: { role: LotRole; can: string }[] = [
    { role: 'owner', can: 'everything, including billing and removing staff' },
    { role: 'manager', can: 'stock, prices, bookings, all leads, analytics' },
    { role: 'sales', can: 'add cars, own leads and bookings, share, live location' },
];
</script>

<template>
    <Head title="Staff" />
    <DealerLayout>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-[30px] font-bold">Staff</h1>
                <span class="text-[14px] text-muted">
                    {{ seats.used }} of {{ seats.limit ?? 'unlimited' }} seats used<template v-if="seats.plan"> on {{ seats.plan }}</template>
                </span>
            </div>
            <button v-if="canManage" type="button" class="btn btn-primary h-11 text-[14px]" @click="showInvite = !showInvite">+ Invite staff</button>
        </div>

        <div v-if="showInvite && canManage" class="card p-5">
            <InviteForm :lot-slug="slug" />
            <p class="mt-2 text-[13px] text-muted">They'll get a link on WhatsApp (or SMS) or by email. Invites expire after 7 days.</p>
        </div>

        <div class="grid gap-5 xl:grid-cols-[1fr_320px]">
            <div class="card overflow-x-auto">
                <table class="w-full min-w-[520px] text-left text-[14px]">
                    <thead class="text-[13px] text-muted">
                        <tr>
                            <th class="px-4 py-3 font-medium">Name</th>
                            <th class="px-4 py-3 font-medium">Role</th>
                            <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-divider border-t border-divider">
                        <tr v-for="member in members" :key="member.ulid">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-sand text-[13px] font-bold text-forest">{{ initials(member.name) }}</span>
                                    <div>
                                        <div class="font-semibold">{{ member.name ?? 'New member' }}</div>
                                        <div class="text-[13px] text-muted">{{ member.phone }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <select
                                    v-if="canManage && member.role !== 'owner'"
                                    :value="member.role"
                                    class="field h-10 w-32"
                                    :aria-label="`Role for ${member.name}`"
                                    @change="changeRole(member.ulid, ($event.target as HTMLSelectElement).value)"
                                >
                                    <option value="manager">Manager</option>
                                    <option value="sales">Sales</option>
                                </select>
                                <span v-else class="capitalize">{{ member.role }}</span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <span v-if="member.is_me" class="text-[13px] text-muted">You</span>
                                <button
                                    v-else-if="canManage && member.role !== 'owner'"
                                    type="button"
                                    class="text-[13px] font-semibold text-clay hover:text-clay-dark"
                                    @click="remove(member.ulid, member.name)"
                                >
                                    Remove
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="invitations.length" class="border-t border-divider p-4">
                    <h2 class="mb-2 font-sans text-[15px] font-bold">Pending invites</h2>
                    <ul class="divide-y divide-divider">
                        <li v-for="invite in invitations" :key="invite.id" class="flex flex-wrap items-center justify-between gap-2 py-2.5 text-[14px]">
                            <span>{{ invite.contact }} · <span class="capitalize">{{ invite.role }}</span> · sent by {{ invite.channel }}</span>
                            <span v-if="canManage" class="flex gap-4">
                                <button
                                    type="button"
                                    class="font-semibold text-forest"
                                    @click="router.post(route('dealer.staff.invitations.resend', [slug, invite.id]), {}, { preserveScroll: true })"
                                >
                                    Resend
                                </button>
                                <button
                                    type="button"
                                    class="font-semibold text-clay"
                                    @click="router.delete(route('dealer.staff.invitations.cancel', [slug, invite.id]), { preserveScroll: true })"
                                >
                                    Cancel
                                </button>
                            </span>
                        </li>
                    </ul>
                    <p class="mt-1 text-[13px] text-muted">Invites expire after 7 days.</p>
                </div>
            </div>

            <aside class="card flex flex-col gap-3 p-5">
                <h2 class="font-sans text-[15px] font-bold">What each role can do</h2>
                <div v-for="r in roles" :key="r.role" class="text-[14px]">
                    <strong class="capitalize">{{ r.role }}</strong>
                    <span class="text-muted"> · {{ r.can }}</span>
                </div>
            </aside>
        </div>
    </DealerLayout>
</template>

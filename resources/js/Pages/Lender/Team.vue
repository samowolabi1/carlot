<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import LenderLayout from '@/layouts/LenderLayout.vue';
import type { SharedProps } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineProps<{
    members: { ulid: string; name: string; email: string | null; role: string; me: boolean; open: number }[];
    roles: { value: string; label: string }[];
    can_manage: boolean;
}>();

const page = usePage<SharedProps>();
const slug = computed(() => page.props.currentLender!.slug);
const form = useForm({ name: '', email: '', role: 'officer' });
const removing = ref<string | null>(null);

function add() {
    form.post(route('lender.team.store', slug.value), { preserveScroll: true, onSuccess: () => form.reset() });
}

function remove(ulid: string) {
    router.delete(route('lender.team.destroy', [slug.value, ulid]), { preserveScroll: true, onFinish: () => (removing.value = null) });
}
</script>

<template>
    <Head title="Team" />
    <LenderLayout>
        <div>
            <h1 class="text-[30px] font-bold">Team</h1>
            <p class="text-[14px] text-muted">Officers work applications. Admins also manage the team and settings.</p>
        </div>

        <ul class="card flex flex-col divide-y divide-line">
            <li v-for="m in members" :key="m.ulid" class="flex flex-wrap items-center gap-3 px-4 py-3">
                <span class="flex min-w-0 grow flex-col">
                    <strong class="text-[15px]">{{ m.name }} <span v-if="m.me" class="font-normal text-muted">(you)</span></strong>
                    <span class="truncate text-[13px] text-muted">{{ m.email }} · {{ roles.find((r) => r.value === m.role)?.label }} · {{ m.open }} open</span>
                </span>
                <template v-if="can_manage && !m.me">
                    <button v-if="removing !== m.ulid" type="button" class="btn btn-outline h-11" @click="removing = m.ulid">Remove</button>
                    <template v-else>
                        <button type="button" class="btn btn-primary h-11" @click="remove(m.ulid)">Yes, remove</button>
                        <button type="button" class="btn btn-outline h-11" @click="removing = null">Keep</button>
                    </template>
                </template>
            </li>
        </ul>
        <InputError :message="(page.props.errors as Record<string, string>).member" />

        <form v-if="can_manage" class="card flex flex-col gap-4 p-5" aria-labelledby="add-title" @submit.prevent="add">
            <h2 id="add-title" class="font-sans text-[17px] font-bold">Add someone</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                <label class="field-label">
                    Name
                    <input v-field="'person_name'" v-model="form.name" class="field h-11" required />
                    <InputError :message="form.errors.name" />
                </label>
                <label class="field-label">
                    Work email
                    <input v-field="'email'" v-model="form.email" type="email" class="field h-11" required />
                    <InputError :message="form.errors.email" />
                </label>
                <label class="field-label">
                    Role
                    <select v-model="form.role" class="field h-11">
                        <option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option>
                    </select>
                </label>
            </div>
            <button type="submit" class="btn btn-primary h-11 self-start" :disabled="form.processing"><Icon name="plus" :size="18" /> Add to the team</button>
            <p class="text-[12px] text-muted">We email them how to sign in. They sign in with a one-time code sent to that email.</p>
        </form>
    </LenderLayout>
</template>

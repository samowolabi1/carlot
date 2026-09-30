<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps<{ lotSlug: string }>();

const form = useForm({ contact: '', role: 'sales' });

function submit() {
    form.post(route('dealer.staff.invite', props.lotSlug), {
        preserveScroll: true,
        onSuccess: () => form.reset('contact'),
    });
}
</script>

<template>
    <form class="flex flex-col gap-3 md:flex-row md:items-start" @submit.prevent="submit">
        <label class="field-label grow">
            <span class="sr-only">Phone number or email</span>
            <input v-field="{ kind: 'text', max: 190 }" v-model="form.contact" class="field" required placeholder="Phone number or email" autocomplete="off" />
            <InputError :message="form.errors.contact" />
        </label>
        <label class="field-label">
            <span class="sr-only">Role</span>
            <select v-model="form.role" class="field md:w-40">
                <option value="sales">Sales</option>
                <option value="manager">Manager</option>
            </select>
            <InputError :message="form.errors.role" />
        </label>
        <button type="submit" class="btn btn-dark" :disabled="form.processing">Send invite</button>
    </form>
</template>

<script setup lang="ts">
import GoogleLogo from '@/components/GoogleLogo.vue';
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    phone: string | null;
    email: string | null;
    hasPassword: boolean;
    passwordChanged: string | null;
    google: { enabled: boolean; connected: boolean };
    isAdmin: boolean;
}>();

const editing = ref(false);
const removing = ref(false);
const show = ref(false);
const form = useForm({ current_password: '', password: '', password_confirmation: '' });
const removeForm = useForm({ current_password: '' });

function save() {
    form.put(route('account.password'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            editing.value = false;
        },
    });
}

function remove() {
    removeForm.delete(route('account.password.destroy'), {
        preserveScroll: true,
        onSuccess: () => {
            removeForm.reset();
            removing.value = false;
        },
    });
}

function disconnectGoogle() {
    router.delete(route('account.google.destroy'), { preserveScroll: true });
}

const row = 'flex items-center gap-3 border-t border-divider px-4 py-3.5 first:border-t-0';
</script>

<template>
    <Head title="Sign-in and security" />
    <CustomerLayout active="account">
        <div class="mx-auto flex max-w-xl flex-col gap-4 px-5 pt-4 pb-28 md:pt-8">
            <div class="flex items-center gap-2">
                <Link :href="route('account')" aria-label="Back" class="-ml-3 flex h-11 w-11 items-center justify-center text-ink"><Icon name="chevronLeft" :size="22" :stroke-width="2" /></Link>
                <h1 class="text-[22px] font-bold">Sign-in and security</h1>
            </div>
            <p class="text-[14px] text-muted">One-time codes always work. Add a password or Google if you'd rather sign in that way.</p>

            <section class="card overflow-hidden" aria-labelledby="codes-heading">
                <h2 id="codes-heading" class="border-b border-divider px-4 py-3 font-sans text-[15px] font-bold">One-time codes</h2>
                <div v-if="phone" :class="row">
                    <Icon name="whatsapp" :size="20" class="shrink-0 text-[#1FAF57]" />
                    <span class="flex grow flex-col"><span class="text-[15px]">{{ phone }}</span><span class="text-[13px] text-muted">Codes by WhatsApp</span></span>
                </div>
                <div v-if="email" :class="row">
                    <Icon name="mail" :size="20" class="shrink-0 text-muted" />
                    <span class="flex min-w-0 grow flex-col"><span class="truncate text-[15px]">{{ email }}</span><span class="text-[13px] text-muted">Codes by email</span></span>
                </div>
            </section>

            <section class="card flex flex-col overflow-hidden" aria-labelledby="password-heading">
                <div :class="row">
                    <Icon name="key" :size="20" class="shrink-0 text-muted" />
                    <span class="flex grow flex-col">
                        <h2 id="password-heading" class="font-sans text-[15px] font-bold">Password</h2>
                        <span class="text-[13px] text-muted">{{ hasPassword ? `On${passwordChanged ? ` · changed ${passwordChanged}` : ''}` : 'Not set. Sign in with your ' + (email ? 'email' : 'WhatsApp number') + ' and a password.' }}</span>
                    </span>
                    <button v-if="!editing" type="button" class="btn btn-outline h-11 shrink-0 px-4 text-[14px]" @click="(editing = true), (removing = false)">
                        {{ hasPassword ? 'Change' : 'Add password' }}
                    </button>
                </div>

                <form v-if="editing" class="flex flex-col gap-3 border-t border-divider p-4" @submit.prevent="save">
                    <label v-if="hasPassword" class="field-label">
                        Current password
                        <input v-model="form.current_password" :type="show ? 'text' : 'password'" autocomplete="current-password" required class="field" />
                        <InputError :message="form.errors.current_password" />
                    </label>
                    <label class="field-label">
                        New password
                        <input v-model="form.password" :type="show ? 'text' : 'password'" autocomplete="new-password" required minlength="8" class="field" />
                        <span class="font-normal text-muted">At least 8 characters, with letters and numbers.</span>
                        <InputError :message="form.errors.password" />
                    </label>
                    <label class="field-label">
                        Type it again
                        <input v-model="form.password_confirmation" :type="show ? 'text' : 'password'" autocomplete="new-password" required class="field" />
                    </label>
                    <label class="flex min-h-11 cursor-pointer items-center gap-3 text-[14px]"><input v-model="show" type="checkbox" class="h-5 w-5 accent-forest" />Show passwords</label>
                    <div class="flex gap-2">
                        <button type="submit" class="btn btn-dark h-11 text-[14px]" :disabled="form.processing">{{ hasPassword ? 'Change password' : 'Save password' }}</button>
                        <button type="button" class="btn btn-outline h-11 text-[14px]" @click="(editing = false), form.reset(), form.clearErrors()">Cancel</button>
                    </div>
                </form>

                <template v-if="hasPassword && !isAdmin && !editing">
                    <button v-if="!removing" type="button" class="min-h-11 border-t border-divider px-4 text-left text-[14px] font-semibold text-danger" @click="removing = true">Remove password</button>
                    <form v-else class="flex flex-col gap-3 border-t border-divider p-4" @submit.prevent="remove">
                        <p class="text-[14px] text-muted">You'll sign in with a one-time code{{ google.connected ? ' or Google' : '' }} instead.</p>
                        <label class="field-label">
                            Current password
                            <input v-model="removeForm.current_password" type="password" autocomplete="current-password" required class="field" />
                            <InputError :message="removeForm.errors.current_password" />
                        </label>
                        <div class="flex gap-2">
                            <button type="submit" class="btn h-11 border-danger bg-white text-[14px] text-danger" :disabled="removeForm.processing">Remove password</button>
                            <button type="button" class="btn btn-outline h-11 text-[14px]" @click="(removing = false), removeForm.reset(), removeForm.clearErrors()">Cancel</button>
                        </div>
                    </form>
                </template>
            </section>

            <section v-if="google.enabled || google.connected" class="card overflow-hidden" aria-labelledby="google-heading">
                <div :class="row">
                    <GoogleLogo class="shrink-0" />
                    <span class="flex grow flex-col">
                        <h2 id="google-heading" class="font-sans text-[15px] font-bold">Google</h2>
                        <span class="text-[13px] text-muted">{{ google.connected ? 'Connected. Use "Continue with Google" to sign in.' : 'Sign in with your Google account.' }}</span>
                    </span>
                    <button v-if="google.connected" type="button" class="btn btn-outline h-11 shrink-0 px-4 text-[14px]" @click="disconnectGoogle">Disconnect</button>
                    <a v-else :href="route('login.google')" class="btn btn-outline h-11 shrink-0 px-4 text-[14px] no-underline">Connect</a>
                </div>
            </section>

            <p class="text-[13px] text-muted">We email you{{ email ? '' : ' (when your account has an email)' }} and add a notification whenever any of this changes.</p>
        </div>
    </CustomerLayout>
</template>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{ backHref: string; processing?: boolean }>();
</script>

<template>
    <div class="flex justify-between">
        <Link :href="backHref" class="btn btn-outline">Back</Link>
        <button type="submit" class="btn btn-primary" :disabled="processing">Save and continue</button>
    </div>
</template>

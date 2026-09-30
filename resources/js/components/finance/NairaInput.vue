<script setup lang="ts">
import { formatNaira, parseAmount, typedAmount } from '@/lib/format';
import { ref, watch } from 'vue';

// A money field that shows "₦1,300,000" while holding a plain number of naira.
const model = defineModel<number>({ required: true });
defineProps<{ label: string; id?: string }>();

const text = ref(model.value ? formatNaira(model.value) : '');

watch(model, (value) => {
    if ((parseAmount(text.value) ?? 0) !== value) text.value = value ? formatNaira(value) : '';
});

function onInput(e: Event) {
    const value = typedAmount((e.target as HTMLInputElement).value) ?? 0;
    model.value = value;
    text.value = value ? formatNaira(value) : '';
}
</script>

<template>
    <label class="field-label">
        {{ label }}
        <input v-field="{ kind: 'money', min: 0, max: 10000000000 }" :id="id" :value="text" class="field" inputmode="numeric" autocomplete="off" placeholder="₦0" @input="onInput" />
    </label>
</template>

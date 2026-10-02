<script setup lang="ts">
import { computed, ref, useId } from 'vue';

type Item = { value: string | number; label: string; count: number; group?: string };

/*
 * A list of choices with how many cars each has. Long lists show the most popular few (plus anything chosen)
 * and a "Show all" that opens the whole list with a search box, scrolling inside the panel.
 */
const props = withDefaults(defineProps<{ options: Item[]; selected: (string | number)[]; label: string; limit?: number; groups?: Record<string, string> }>(), {
    limit: 6,
    groups: undefined,
});
const emit = defineEmits<{ toggle: [value: string | number] }>();

const id = useId();
const expanded = ref(false);
const query = ref('');
const long = computed(() => props.options.length > props.limit + 2);
const searchable = computed(() => props.options.length > 10);

const visible = computed(() => {
    if (!long.value) return props.options;
    if (expanded.value) {
        const q = query.value.trim().toLowerCase();
        return q ? props.options.filter((o) => o.label.toLowerCase().includes(q)) : props.options;
    }
    const keep = new Set<string | number>([...props.options].sort((a, b) => b.count - a.count).slice(0, props.limit).map((o) => o.value));
    props.selected.forEach((v) => keep.add(v));
    return props.options.filter((o) => keep.has(o.value));
});

// Feature lists come in groups (Comfort, Safety, Tech).
const sections = computed(() => {
    if (!props.groups) return [{ key: '', title: '', items: visible.value }];
    return Object.entries(props.groups)
        .map(([key, title]) => ({ key, title, items: visible.value.filter((o) => o.group === key) }))
        .filter((s) => s.items.length);
});

function collapse() {
    expanded.value = false;
    query.value = '';
}
</script>

<template>
    <div class="flex flex-col gap-1">
        <input
            v-if="expanded && searchable"
            v-model="query"
            v-field="{ kind: 'text', max: 40 }"
            type="search"
            class="field mb-1 h-11"
            :placeholder="`Search ${label.toLowerCase()}`"
            :aria-label="`Search ${label.toLowerCase()}`"
            :aria-controls="id"
        />
        <div :id="id" :class="expanded ? 'max-h-80 overflow-y-auto overscroll-contain pr-1' : ''">
            <div v-for="section in sections" :key="section.key">
                <p v-if="section.title" class="pt-2 pb-1 text-[12px] font-semibold tracking-wide text-muted uppercase">{{ section.title }}</p>
                <label v-for="o in section.items" :key="o.value" class="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg px-1 text-[14px] hover:bg-ivory">
                    <input type="checkbox" class="size-[18px] shrink-0 accent-forest" :checked="selected.includes(o.value)" @change="emit('toggle', o.value)" />
                    <span class="grow">{{ o.label }}</span>
                    <span class="text-[13px] text-muted tabular-nums">{{ o.count.toLocaleString('en-NG') }}</span>
                </label>
            </div>
            <p v-if="expanded && !visible.length" class="px-1 py-2 text-[14px] text-muted">Nothing matches "{{ query }}".</p>
        </div>
        <button
            v-if="long"
            type="button"
            class="flex min-h-11 items-center self-start px-1 text-[14px] font-semibold text-forest underline-offset-2 hover:underline"
            :aria-expanded="expanded"
            :aria-controls="id"
            @click="expanded ? collapse() : (expanded = true)"
        >
            {{ expanded ? 'Show fewer' : `Show all ${options.length}` }}
        </button>
    </div>
</template>

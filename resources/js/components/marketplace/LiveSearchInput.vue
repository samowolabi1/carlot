<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { MIN_LENGTH, useSuggestions, type Suggestion } from '@/composables/useSuggestions';
import { router } from '@inertiajs/vue3';
import { computed, ref, useId } from 'vue';

/**
 * The marketplace search box: suggestions (makes, models, places, lots and cars) appear under it as
 * people type. Arrow keys move through them, Enter opens one (or searches for the text), Escape closes.
 * Put it inside a `relative` wrapper: the list is positioned under that wrapper.
 */
const query = defineModel<string>({ required: true });
const props = withDefaults(defineProps<{ inputClass?: string; placeholder?: string; withCars?: boolean }>(), {
    inputClass: 'h-11 w-full bg-transparent text-[15px] outline-none',
    placeholder: 'Search make, model or lot',
    withCars: true,
});
const emit = defineEmits<{ submit: [] }>();

const { results, loading } = useSuggestions(query, props.withCars);
const open = ref(false);
const active = ref(-1);
const id = useId();

const groups = computed(() => {
    const r = results.value;
    if (!r) return [];
    return [
        { key: 'makes', title: 'Makes and models', items: [...r.makes, ...r.models] },
        { key: 'places', title: 'Places', items: r.places },
        { key: 'lots', title: 'Car lots', items: r.lots },
        { key: 'cars', title: 'Cars', items: r.cars },
    ].filter((g) => g.items.length > 0);
});
const flat = computed(() => groups.value.flatMap((g) => g.items));
const showList = computed(() => open.value && query.value.trim().length >= MIN_LENGTH && (flat.value.length > 0 || (!loading.value && results.value !== null)));

function indexOf(item: Suggestion) {
    return flat.value.indexOf(item);
}

function pick(item: Suggestion) {
    open.value = false;
    router.visit(item.url);
}

function onKeydown(e: KeyboardEvent) {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        if (!flat.value.length) return;
        e.preventDefault();
        open.value = true;
        const n = flat.value.length;
        active.value = e.key === 'ArrowDown' ? (active.value + 1) % n : (active.value - 1 + n) % n;
    } else if (e.key === 'Enter') {
        const item = flat.value[active.value];
        const listed = showList.value;
        open.value = false;
        if (item && listed) {
            e.preventDefault();
            pick(item);
        } else {
            emit('submit');
        }
    } else if (e.key === 'Escape') {
        open.value = false;
        active.value = -1;
    }
}

function onInput() {
    open.value = true;
    active.value = -1;
}

// Let a click on a suggestion land before the list closes.
function onBlur() {
    setTimeout(() => (open.value = false), 150);
}
</script>

<template>
    <input
        v-model="query"
        type="search"
        maxlength="80"
        :class="inputClass"
        :placeholder="placeholder"
        aria-label="Search cars"
        enterkeyhint="search"
        autocomplete="off"
        role="combobox"
        aria-autocomplete="list"
        :aria-expanded="showList"
        :aria-controls="`${id}-list`"
        :aria-activedescendant="active >= 0 ? `${id}-opt-${active}` : undefined"
        @input="onInput"
        @focus="open = true"
        @blur="onBlur"
        @keydown="onKeydown"
    />
    <Icon v-if="loading && query.trim().length >= MIN_LENGTH" name="refresh" :size="16" class="shrink-0 animate-spin text-muted" aria-hidden="true" />
    <div
        v-show="showList"
        :id="`${id}-list`"
        role="listbox"
        aria-label="Suggestions"
        class="absolute top-full right-0 left-0 z-40 mt-1.5 max-h-[70vh] overflow-y-auto rounded-2xl border border-line bg-white py-2 text-left shadow-lg"
    >
        <p v-if="flat.length === 0" class="px-4 py-3 text-[14px] text-muted">No matches for "{{ query.trim() }}". Press Enter to search all cars.</p>
        <div v-for="group in groups" :key="group.key" class="py-1">
            <p class="px-4 pt-1 pb-1 text-[11px] font-semibold tracking-wide text-muted uppercase">{{ group.title }}</p>
            <a
                v-for="item in group.items"
                :id="`${id}-opt-${indexOf(item)}`"
                :key="item.url"
                :href="item.url"
                role="option"
                :aria-selected="indexOf(item) === active"
                class="flex min-h-11 items-center gap-3 px-4 py-1.5 text-[15px] text-ink hover:bg-ivory hover:text-ink"
                :class="{ 'bg-ivory': indexOf(item) === active }"
                @mousedown.prevent
                @click.prevent="pick(item)"
                @mouseenter="active = indexOf(item)"
            >
                <img v-if="item.image" :src="item.image" alt="" class="h-10 w-14 shrink-0 rounded-md object-cover" loading="lazy" />
                <Icon v-else-if="group.key !== 'cars'" :name="group.key === 'places' ? 'pin' : group.key === 'lots' ? 'grid' : 'search'" :size="16" class="shrink-0 text-muted" />
                <span class="flex min-w-0 grow flex-col">
                    <span class="truncate font-medium">{{ item.label }}</span>
                    <span v-if="item.detail" class="truncate text-[13px] text-muted">{{ item.detail }}</span>
                </span>
                <span v-if="item.count" class="shrink-0 text-[13px] text-muted">{{ item.count }} {{ item.count === 1 ? 'car' : 'cars' }}</span>
            </a>
        </div>
    </div>
</template>

import { FIELDS, fieldProblem, type FieldKind, type FieldOptions } from '@/lib/fields';
import type { Directive } from 'vue';

/**
 * v-field: checks an input as it is typed against the shared field rules (lib/fields.ts).
 *
 *   <input v-model="form.name" v-field="'person_name'" />
 *   <input v-model="form.price" v-field="{ kind: 'money', min: 10000 }" />
 *   <textarea v-model="form.notes" v-field="{ kind: 'text', max: 1000 }" />
 *
 * It sets maxlength/minlength, the keyboard (inputmode) and autocomplete, marks the field invalid
 * (so the browser won't submit the form and :user-invalid styles apply), and shows the problem in a
 * line under the field once the person has typed and moved on. Server errors still come from InputError.
 */

type Binding = FieldKind | FieldOptions;
type FieldEl = (HTMLInputElement | HTMLTextAreaElement) & { __field?: FieldState };

interface FieldState {
    options: FieldOptions;
    hint: HTMLParagraphElement;
    touched: boolean;
    onInput: () => void;
    onBlur: () => void;
    onInvalid: () => void;
}

let counter = 0;

function optionsOf(value: Binding): FieldOptions {
    return typeof value === 'string' ? { kind: value } : value;
}

function labelOf(el: FieldEl, options: FieldOptions): string {
    if (options.label) return options.label;
    const own = el.labels?.[0] ?? el.closest('label');
    const fallback = el.getAttribute('aria-label') ?? el.getAttribute('placeholder') ?? 'This field';
    let text = own?.querySelector('[data-label]')?.textContent ?? '';
    // Otherwise the label's first words: its first text, or its first element that isn't the input or a hint.
    for (const node of Array.from(own?.childNodes ?? [])) {
        if (text.trim()) break;
        if (node.nodeType === Node.TEXT_NODE) text = node.textContent ?? '';
        else if (node instanceof HTMLElement && !node.matches('input, textarea, select, .field-hint')) text = node.textContent ?? '';
    }

    return (text.trim().split('\n')[0] || fallback).replace(/\s*\(optional\)\s*$/i, '').replace(/[*:]\s*$/, '').trim();
}

function applyAttributes(el: FieldEl, options: FieldOptions): void {
    const spec = FIELDS[options.kind];
    const numeric = options.kind === 'money' || options.kind === 'count';
    const max = numeric ? spec.max : (options.max ?? spec.max);
    const min = numeric ? undefined : (options.min ?? spec.min);

    if (max !== undefined) el.maxLength = max;
    if (min !== undefined && el instanceof HTMLInputElement) el.minLength = min;
    if (spec.inputmode && !el.hasAttribute('inputmode')) el.setAttribute('inputmode', spec.inputmode);
    if (spec.autocomplete && !el.hasAttribute('autocomplete')) el.setAttribute('autocomplete', spec.autocomplete);
    if (['email', 'code', 'reference', 'vin', 'otp', 'account_number'].includes(options.kind)) el.spellcheck = false;
}

function validate(el: FieldEl, state: FieldState): void {
    const problem = fieldProblem(el.value, state.options);
    const message = problem ? `${labelOf(el, state.options)} ${problem}.` : '';

    el.setCustomValidity(message);
    const show = message !== '' && state.touched;
    el.toggleAttribute('aria-invalid', show);
    state.hint.textContent = show ? message : '';
    state.hint.hidden = !show;
    el.title = show ? message : '';
}

export const field: Directive<FieldEl, Binding> = {
    mounted(el, binding) {
        const options = optionsOf(binding.value);
        const hint = document.createElement('p');
        hint.id = `field-hint-${++counter}`;
        hint.className = 'field-hint mt-1 text-[13px] font-medium text-danger';
        hint.setAttribute('role', 'alert');
        hint.hidden = true;
        // Inside the field's <label> when it has one; right after the input unless that sits in a grid or a row,
        // where an extra line would shift the layout (there the red border and the browser's message do the job).
        const label = el.closest('label');
        const parent = el.parentElement;
        const inRow = parent !== null && /grid|flex/.test(getComputedStyle(parent).display) && getComputedStyle(parent).flexDirection !== 'column';
        if (label && label.contains(el)) label.append(hint);
        else if (!inRow) el.insertAdjacentElement('afterend', hint);
        el.setAttribute('aria-describedby', [el.getAttribute('aria-describedby'), hint.id].filter(Boolean).join(' '));

        const state: FieldState = {
            options,
            hint,
            touched: false,
            onInput: () => validate(el, state),
            onBlur: () => {
                if (el.value.trim() !== '') state.touched = true;
                validate(el, state);
            },
            onInvalid: () => {
                state.touched = true;
                validate(el, state);
            },
        };
        el.__field = state;
        applyAttributes(el, options);
        el.addEventListener('input', state.onInput);
        el.addEventListener('blur', state.onBlur);
        // A submit attempt shows every problem at once.
        el.addEventListener('invalid', state.onInvalid);
        validate(el, state);
    },
    updated(el, binding) {
        const state = el.__field;
        if (!state) return;
        state.options = optionsOf(binding.value);
        applyAttributes(el, state.options);
        // v-model can change the value without an input event (form reset, prefill).
        validate(el, state);
    },
    unmounted(el) {
        const state = el.__field;
        if (!state) return;
        el.removeEventListener('input', state.onInput);
        el.removeEventListener('blur', state.onBlur);
        el.removeEventListener('invalid', state.onInvalid);
        state.hint.remove();
        delete el.__field;
    },
};

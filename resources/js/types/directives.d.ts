import type { field } from '../directives/field';

// Globally registered in app.ts, so templates can use v-field without importing it.
declare module 'vue' {
    interface GlobalDirectives {
        vField: typeof field;
    }
}

export {};

// ESLint flat config for the Vue 3 / Vite front end (TST-04). Scope: resources/js only — the
// built assets in public/build are generated and committed, never linted. Rule set: ESLint
// recommended + eslint-plugin-vue "flat/essential" (the Vue 3 correctness tier: unused vars,
// v-for keys, duplicate attributes, undefined components …), not the stylistic tiers — style is
// Prettier's job and the codebase predates this gate. Raise to "flat/recommended" deliberately.
import js from '@eslint/js';
import pluginVue from 'eslint-plugin-vue';
import globals from 'globals';

export default [
    {
        ignores: ['public/**', 'vendor/**', 'node_modules/**', 'storage/**', 'bootstrap/**', '.prod-ready/**'],
    },
    js.configs.recommended,
    ...pluginVue.configs['flat/essential'],
    {
        files: ['resources/js/**/*.{js,mjs,vue}'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: { ...globals.browser, ...globals.node },
        },
        rules: {
            // `_`-prefixed and destructured-rest names are intentional leftovers, not bugs
            'no-unused-vars': ['error', { argsIgnorePattern: '^_', varsIgnorePattern: '^_', ignoreRestSiblings: true }],
            // multi-word component names: the project's page components are intentionally single-word
            // (Index, Create, Edit …) under Inertia's Pages/<Module>/ convention
            'vue/multi-word-component-names': 'off',
        },
    },
    {
        // PatientForm receives the parent's Inertia `useForm` object as its `form` prop and binds
        // fields to it with v-model on purpose: the prop IS the reactive form store, shared between
        // the Modify modal and the Create page, so the "mutation" is the intended write path. The
        // rule would flag every field; documented exception rather than a blanket disable.
        files: ['resources/js/Components/PatientForm.vue'],
        rules: { 'vue/no-mutating-props': 'off' },
    },
    {
        // specs run in jsdom via Vitest with globals: true
        files: ['resources/js/**/__tests__/**', 'resources/js/**/*.{spec,test}.js'],
        languageOptions: { globals: { ...globals.browser, ...globals.node, ...globals.vitest } },
    },
];

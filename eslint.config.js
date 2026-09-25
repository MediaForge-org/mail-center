import js from '@eslint/js';
import globals from 'globals';
import tseslint from 'typescript-eslint';
import vue from 'eslint-plugin-vue';

export default [
    { ignores: ['node_modules/**', 'public/build/**', 'graphify-out/**'] },
    js.configs.recommended,
    ...tseslint.configs.recommended,
    ...vue.configs['flat/essential'],
    {
        files: ['**/*.{ts,vue}'],
        languageOptions: {
            globals: { ...globals.browser, ...globals.node },
            parserOptions: { parser: tseslint.parser },
        },
        rules: { 'vue/no-v-html': 'error' },
    },
];

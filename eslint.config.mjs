// eslint.config.mjs — ataraxialab.ch
// ESLint v10 flat config — deux environnements : Node CJS + browser vanilla
// "type": "commonjs" dans package.json → .mjs pour garantir ESM sans ambiguïté

import js from '@eslint/js';
import globals from 'globals';
import { defineConfig } from 'eslint/config';

export default defineConfig([
  // ── Node.js CJS : _data/*.js + eleventy.config.js ──────────────────────
  {
    files: ['src/_data/**/*.js', 'eleventy.config.js'],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: 'commonjs',
      globals: { ...globals.node },
    },
    extends: [js.configs.recommended],
    rules: {
      'no-unused-vars': ['warn', { varsIgnorePattern: '^_', argsIgnorePattern: '^_' }],
      'no-undef': 'error',
      'no-console': 'off',
    },
  },

  // ── Browser vanilla : src/js/*.js ───────────────────────────────────────
  {
    files: ['src/js/**/*.js'],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: 'script',
      globals: { ...globals.browser },
    },
    extends: [js.configs.recommended],
    rules: {
      'no-var': 'off',
      'no-unused-vars': ['warn', { varsIgnorePattern: '^_', argsIgnorePattern: '^_' }],
      'no-undef': 'error',
      'no-console': 'off',
      'no-debugger': 'error',
    },
  },

  // ── Ignores ──────────────────────────────────────────────────────────────
  {
    ignores: ['_site/**', 'node_modules/**'],
  },
]);

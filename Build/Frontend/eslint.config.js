import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import reactHooks from 'eslint-plugin-react-hooks';

/**
 * Lint for the two things the compiler cannot see.
 *
 * Types are TypeScript's job and `npm run lint` runs `tsc` first, so this
 * configuration is deliberately short: the rules of hooks (a dependency array
 * that lies is a bug that only shows up under load) and the small set of
 * correctness rules that catch a typo rather than a style.
 */
export default tseslint.config(
  { ignores: ['**/dist/**', '**/node_modules/**'] },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  reactHooks.configs['recommended-latest'],
  {
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: 'module',
      globals: {
        window: 'readonly',
        document: 'readonly',
        console: 'readonly',
        fetch: 'readonly',
        setTimeout: 'readonly',
        clearTimeout: 'readonly',
      },
    },
    rules: {
      '@typescript-eslint/no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
      '@typescript-eslint/consistent-type-imports': ['error', { prefer: 'type-imports' }],
      eqeqeq: ['error', 'always'],
      'no-console': ['error', { allow: ['warn', 'error'] }],
    },
  },
  {
    // The launcher is hand-written ES module JavaScript loaded straight by the
    // backend: no build step, no types, and that is the point of it.
    files: ['Resources/Public/JavaScript/toolbar/*.js'],
    ...tseslint.configs.disableTypeChecked,
  },
);

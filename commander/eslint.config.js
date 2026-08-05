import js from '@eslint/js';
import globals from 'globals';
import svelte from 'eslint-plugin-svelte';
import ts from 'typescript-eslint';
import svelteConfig from './svelte.config.js';

export default ts.config(
	js.configs.recommended,
	...ts.configs.strictTypeChecked,
	...svelte.configs.recommended,
	{
		languageOptions: {
			globals: { ...globals.browser, ...globals.node },
			parserOptions: {
				projectService: true,
				tsconfigRootDir: import.meta.dirname,
				// Sin esto el servicio de proyecto rechaza los .svelte por extensión no estándar.
				extraFileExtensions: ['.svelte']
			}
		}
	},
	{
		files: ['**/*.svelte', '**/*.svelte.ts'],
		languageOptions: {
			parserOptions: { parser: ts.parser, svelteConfig }
		}
	},
	{
		rules: {
			// El borde valida y a partir de ahí todo está tipado. `any` es la grieta por la que
			// vuelve a entrar la cadena inventada que `decode` acaba de rechazar.
			'@typescript-eslint/no-explicit-any': 'error',
			'@typescript-eslint/consistent-type-imports': 'error',
			'@typescript-eslint/no-unnecessary-condition': 'error',
			// Una cifra dentro de una plantilla es exactamente lo que hace el formateo de esta
			// consola. La regla existe para atrapar objetos y `null`, no números.
			'@typescript-eslint/restrict-template-expressions': ['error', { allowNumber: true }]
		}
	},
	{ ignores: ['build/', '.svelte-kit/', 'node_modules/'] }
);

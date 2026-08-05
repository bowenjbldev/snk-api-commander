import '@testing-library/jest-dom/vitest';

// jsdom no trae ResizeObserver y ECharts lo usa para redimensionar. Un doble vacío basta: los
// tests que importan una gráfica comprueban las opciones, no el pintado.
if (!('ResizeObserver' in globalThis)) {
	Object.defineProperty(globalThis, 'ResizeObserver', {
		writable: true,
		value: class {
			observe(): void {}
			unobserve(): void {}
			disconnect(): void {}
		}
	});
}

if (!('matchMedia' in globalThis)) {
	Object.defineProperty(globalThis, 'matchMedia', {
		writable: true,
		value: (query: string) => ({
			matches: false,
			media: query,
			addEventListener: () => {},
			removeEventListener: () => {}
		})
	});
}

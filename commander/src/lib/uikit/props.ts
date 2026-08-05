/**
 * La API de cada pieza, leída del propio componente.
 *
 * Escribir a mano una tabla de propiedades garantiza que mienta: se renombra una prop y la tabla
 * se queda con el nombre viejo. Aquí se lee el fuente en crudo y se saca de su `interface Props`,
 * con el comentario que ya lleva cada línea. La documentación no puede desincronizarse porque no
 * existe aparte del código.
 */

const sources = import.meta.glob<string>('/src/lib/**/*.svelte', {
	eager: true,
	query: '?raw',
	import: 'default'
});

export interface PropDoc {
	name: string;
	type: string;
	required: boolean;
	/** El valor por defecto tal y como se escribe en el destructurado. */
	fallback?: string;
	/** El comentario de la propiedad, sin los adornos de bloque. */
	doc?: string;
}

export interface ComponentDoc {
	name: string;
	path: string;
	props: PropDoc[];
}

/** El cuerpo de `interface Props`, contando llaves para no cortar en un tipo de objeto. */
function propsBody(source: string): string | null {
	const start = source.indexOf('interface Props {');
	if (start === -1) return null;
	let depth = 0;
	for (let i = source.indexOf('{', start); i < source.length; i++) {
		if (source[i] === '{') depth++;
		else if (source[i] === '}' && --depth === 0)
			return source.slice(source.indexOf('{', start) + 1, i);
	}
	return null;
}

/** Los valores por defecto viven en el destructurado, no en el tipo. */
function fallbacks(source: string): Map<string, string> {
	const out = new Map<string, string>();
	const block = /const\s*\{([\s\S]*?)\}\s*:\s*Props\s*=\s*\$props\(\)/.exec(source);
	for (const part of block?.[1]?.split(',') ?? []) {
		const [name, value] = part.split('=');
		if (name !== undefined && value !== undefined) out.set(name.trim(), value.trim());
	}
	return out;
}

const MEMBER = /^(\w+)(\??):\s*(.+);$/;

function parseProps(source: string): PropDoc[] {
	const body = propsBody(source);
	if (body === null) return [];
	const defaults = fallbacks(source);
	const out: PropDoc[] = [];
	let doc: string[] = [];

	for (const raw of body.split('\n')) {
		const line = raw.trim();
		if (line.startsWith('/*') || line.startsWith('*') || line.startsWith('//')) {
			const text = line.replace(/^\/\*+|^\*+\/?|^\/\/|\*\/$/g, '').trim();
			if (text) doc.push(text);
			continue;
		}
		const member = MEMBER.exec(line);
		if (!member) {
			if (line === '') doc = [];
			continue;
		}
		const [, name = '', optional, type = ''] = member;
		const fallback = defaults.get(name);
		out.push({
			name,
			type: type.replace(/\s*\|\s*undefined$/, ''),
			required: optional !== '?',
			...(fallback !== undefined ? { fallback } : {}),
			...(doc.length ? { doc: doc.join(' ') } : {})
		});
		doc = [];
	}
	return out;
}

/** `/src/lib/ui/Button.demo.svelte` documenta `Button.svelte`, salvo que la demo diga otra cosa. */
export function docsFor(demoSource: string, components?: string[]): ComponentDoc[] {
	const dir = demoSource.slice(0, demoSource.lastIndexOf('/') + 1);
	const own = demoSource.slice(dir.length).replace('.demo.svelte', '');
	return (components ?? [own]).flatMap((name) => {
		const path = `${dir}${name}.svelte`;
		const source = sources[path];
		if (source === undefined) return [];
		const props = parseProps(source);
		return props.length ? [{ name, path, props }] : [];
	});
}

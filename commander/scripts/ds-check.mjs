#!/usr/bin/env node
// Las reglas del design system, comprobadas en vez de recordadas.
//
//   1. Ningún hex fuera de src/lib/tokens/.
//   2. Ningún literal `var(--` en un .ts — para eso está el enum CssVar.
//   3. Una primitiva de ui/ no importa de domain/, api/, nav/ ni state/: no sabe qué es una
//      instancia. La dependencia va en un solo sentido.
//   4. Techo de líneas por capa. Un fichero de mil líneas es el fallo que este proyecto evita.
//   5. Toda primitiva y toda pieza de dominio tiene su .demo.svelte, así la guía viva no puede
//      quedarse atrás.

import { readFileSync } from 'node:fs';
import { readdir } from 'node:fs/promises';
import { join, relative, resolve } from 'node:path';

const ROOT = resolve(import.meta.dirname, '..');
const SRC = join(ROOT, 'src');

// Los números están calibrados contra los ficheros reales del proyecto, no puestos a ojo: el más
// grande hoy es Modal (75 de lógica) y attention-rules (114). Suben solo con un fichero delante
// que justifique la subida.
//
// Un SFC de Svelte lleva lógica y estilos en el mismo fichero, así que un techo plano castiga el
// CSS en vez de la complejidad. Se miden por separado: `logic` es script + marcado —lo que hay
// que entender para saber qué hace— y `total` es el fichero entero, como red contra un CSS que se
// desmadra. En un .ts los dos números son el mismo.
const LIMITS = [
	{ prefix: 'src/lib/ui/', logic: 80, total: 160 },
	{ prefix: 'src/lib/domain/', logic: 130, total: 240 },
	{ prefix: 'src/routes/', logic: 150, total: 260 },
	{ prefix: 'src/lib/', logic: 250, total: 250 }
];
// Excepciones con nombre y motivo, no un techo más alto para todos. Subir el techo de la capa
// para que quepa un fichero se lo regala a los otros cuarenta; nombrarlo obliga a defender el
// siguiente. Solo se permite en `total`: la lógica no tiene excepciones.
const TOTAL_EXCEPTIONS = {
	// Es el único control con una matriz de verdad —4 tamaños × 3 variantes × 3 tonos, más caret y
	// ancho completo— y eso son tablas de declaraciones CSS, no complejidad: 68 líneas de lógica
	// sobre un techo de 80. Repartirlo en dos ficheros solo escondería la matriz.
	'src/lib/ui/Button.svelte': 180
};

// Una demo que ejercita un compuesto entero —una tabla con sus nueve piezas, las cuatro capas—
// es legítimamente más larga: su trabajo es la cobertura, no la brevedad.
const DEMO_LIMIT = { logic: 130, total: 220 };
const HEX = /#[0-9a-fA-F]{3,8}\b/;
const FORBIDDEN_FROM_UI = /from\s+'\$lib\/(domain|api|nav|state)\//;

const problems = [];

async function walk(dir) {
	const out = [];
	for (const entry of await readdir(dir, { withFileTypes: true })) {
		const full = join(dir, entry.name);
		if (entry.isDirectory()) out.push(...(await walk(full)));
		else out.push(full);
	}
	return out;
}

const files = (await walk(SRC)).filter((f) => /\.(ts|svelte|css)$/.test(f));

const demoFiles = files.filter((f) => f.endsWith('.demo.svelte'));
const ownDemos = new Set(demoFiles.map((f) => f.replace('.demo.svelte', '')));
const demoSources = demoFiles.map((f) => readFileSync(f, 'utf8')).join('\n');

// Una pieza sale en la guía si tiene su propia demo o si la demo de su compuesto la usa: Th, Td y
// TableRow solo significan algo dentro de una Table, y cuatro demos clónicas no enseñan nada.
function covered(file) {
	if (ownDemos.has(file.replace('.svelte', ''))) return true;
	const name = file.split('/').pop();
	return name !== undefined && demoSources.includes(`/${name}`);
}

for (const file of files) {
	const rel = relative(ROOT, file).replaceAll('\\', '/');
	const source = readFileSync(file, 'utf8');
	const lines = source.split('\n');
	const isDemo = rel.endsWith('.demo.svelte');
	const isTest = rel.endsWith('.test.ts');

	if (!rel.startsWith('src/lib/tokens/')) {
		lines.forEach((line, i) => {
			if (HEX.test(line)) problems.push(`${rel}:${i + 1} hex suelto — usa un token de color`);
		});
	}

	if (rel.endsWith('.ts') && !rel.startsWith('src/lib/tokens/')) {
		lines.forEach((line, i) => {
			if (line.includes('var(--'))
				problems.push(`${rel}:${i + 1} literal 'var(--' en TypeScript — usa el enum CssVar`);
		});
	}

	// La regla de capas no aplica a las demos: una demo enseña precisamente la primitiva cableada
	// a estado o a rutas reales, que es lo que hará la pantalla.
	if (rel.startsWith('src/lib/ui/') && !isDemo && FORBIDDEN_FROM_UI.test(source)) {
		problems.push(`${rel} una primitiva de ui/ no puede importar de domain/, api/, nav/ ni state/`);
	}

	if (!isTest) {
		const limit = isDemo ? DEMO_LIMIT : LIMITS.find((l) => rel.startsWith(l.prefix));
		if (limit) {
			const logic = source.replace(/<style[\s\S]*?<\/style>/g, '').split('\n').length;
			if (logic > limit.logic) {
				problems.push(
					`${rel} tiene ${logic} líneas de lógica (script + marcado), el techo de su capa es ${limit.logic}`
				);
			}
			const total = TOTAL_EXCEPTIONS[rel] ?? limit.total;
			if (lines.length > total) {
				problems.push(`${rel} tiene ${lines.length} líneas en total, el techo es ${total}`);
			}
		}
	}

	const needsDemo =
		(rel.startsWith('src/lib/ui/') ||
			rel.startsWith('src/lib/domain/') ||
			rel.startsWith('src/lib/viz/')) &&
		rel.endsWith('.svelte') &&
		!isDemo;
	if (needsDemo && !covered(file)) {
		problems.push(`${rel} no sale en /uikit — dale su .demo.svelte o úsalo en la de su compuesto`);
	}
}

if (problems.length) {
	console.error(`ds-check: ${problems.length} problema(s)\n`);
	for (const p of problems) console.error(`  ${p}`);
	process.exit(1);
}

console.log(`ds-check: ${files.length} ficheros, sin problemas`);

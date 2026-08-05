#!/usr/bin/env node
// Capturas de las pantallas en los dos temas, contra un dev server ya levantado.
//
//   npm run dev                                  (o docker compose up -d commander-web)
//   COMMANDER_URL=http://localhost:5174 npm run screenshots
//
// Usa el Chrome del sistema por playwright-core: no descarga un navegador propio. Además falla
// si alguna página deja un error en consola, así que sirve de humo, no solo de álbum.

import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright-core';

const URL_BASE = process.env.COMMANDER_URL ?? 'http://localhost:5174';
const CHROME = process.env.CHROME_BIN ?? '/usr/bin/google-chrome';
const OUT = process.env.COMMANDER_SHOTS ?? '.screenshots';
const THEME_KEY = 'commander.theme';

const PAGES = [
	{ name: 'uikit', path: '/uikit' },
	{ name: 'login', path: '/login' },
	// Las pantallas de dentro exigen sesión; sin backend redirigen a la entrada, que también es
	// un resultado válido que comprobar.
	{ name: 'fleet', path: '/fleet' },
	{ name: 'overview', path: '/overview' }
];

// Ruido del entorno de desarrollo, no de la consola.
const IGNORED = /websocket|favicon|\[vite\]/i;

await mkdir(OUT, { recursive: true });

const browser = await chromium.launch({ executablePath: CHROME, args: ['--no-sandbox'] });
let failures = 0;

for (const theme of ['light', 'dark']) {
	for (const target of PAGES) {
		const page = await browser.newPage({
			viewport: { width: 1440, height: 1000 },
			deviceScaleFactor: 2
		});
		const problems = [];
		page.on('console', (m) => {
			if (m.type() === 'error' && !IGNORED.test(m.text())) problems.push(m.text());
		});
		page.on('pageerror', (e) => {
			if (!IGNORED.test(String(e))) problems.push(String(e.stack ?? e));
		});

		await page.addInitScript(
			([key, value]) => localStorage.setItem(key, value),
			[THEME_KEY, theme]
		);
		const response = await page.goto(`${URL_BASE}${target.path}`, { waitUntil: 'networkidle' });
		await page.screenshot({ path: `${OUT}/${target.name}-${theme}.png`, fullPage: true });

		const status = response?.status() ?? 0;
		const ok = status === 200 && problems.length === 0;
		if (!ok) failures++;
		console.log(
			`${ok ? '✔' : '✘'} ${target.name}-${theme}  ${status}` +
				(problems.length ? `\n    ${problems.slice(0, 3).join('\n    ')}` : '')
		);
		await page.close();
	}
}

await browser.close();
console.log(`\nCapturas en ${OUT}/`);
process.exit(failures ? 1 : 0);

import { describe, expect, it } from 'vitest';
import { cd, date, eur, k, mb, n, pct, rel, stamp } from './index';

describe('n', () => {
	it('agrupa con el separador del locale y redondea', () => {
		expect(n(1240332)).toBe('1.240.332');
		expect(n(412.6)).toBe('413');
	});

	// El español no separa el millar en números de cuatro cifras. Queda escrito porque parece un
	// fallo de agrupación y no lo es.
	it('deja las cuatro cifras sin separador', () => {
		expect(n(3420)).toBe('3420');
		expect(n(13420)).toBe('13.420');
	});
});

describe('k', () => {
	it('compacta con coma decimal', () => {
		expect(k(1_700_000)).toBe('1,7M');
		expect(k(138_400)).toBe('138,4k');
	});

	it('deja las cifras pequeñas enteras', () => {
		expect(k(412)).toBe('412');
		expect(k(999)).toBe('999');
	});
});

describe('eur', () => {
	it('no lleva céntimos', () => {
		expect(eur(3420)).not.toContain(',00');
	});

	// El espacio antes del € es duro (U+00A0) a propósito: un importe no se parte al final de
	// línea. Está escrito explícito para que nadie lo «arregle» a espacio normal.
	it('sigue la regla española: cuatro cifras sin separador, cinco con él', () => {
		expect(eur(3420)).toBe('3420 €');
		expect(eur(13420)).toBe('13.420 €');
	});
});

describe('pct', () => {
	it('usa un decimal solo por debajo del 10 %, donde el entero pierde la señal', () => {
		expect(pct(0.82)).toBe('82 %');
		expect(pct(0.043)).toBe('4,3 %');
	});

	it('deja pasar el exceso por encima del 100 %', () => {
		expect(pct(1.03)).toBe('103 %');
	});
});

describe('rel', () => {
	it('escala de segundos a días', () => {
		expect(rel(45_000)).toBe('45 s');
		expect(rel(12 * 60_000)).toBe('12 min');
		expect(rel(7 * 3_600_000)).toBe('7 h');
		expect(rel(3 * 86_400_000)).toBe('3 d');
	});

	it('no devuelve tiempo negativo', () => {
		expect(rel(-5000)).toBe('0 s');
	});
});

describe('cd', () => {
	const now = 1_000_000_000_000;

	it('da horas y minutos cuando queda más de una hora', () => {
		expect(cd(now + 4 * 3_600_000 + 11 * 60_000, now)).toBe('4 h 11 m');
	});

	it('baja a minutos y segundos en la última hora', () => {
		expect(cd(now + 12 * 60_000 + 3000, now)).toBe('12 m 03 s');
	});

	it('marca el preaviso caducado con signo, no lo aplasta a cero', () => {
		expect(cd(now - 2 * 3_600_000 - 40 * 60_000, now)).toBe('−2 h 40 m');
	});
});

describe('stamp y date', () => {
	const ts = new Date(2026, 5, 11, 14, 2, 10).getTime();

	it('escribe el instante en hora local con ceros a la izquierda', () => {
		expect(stamp(ts)).toBe('11/06 14:02:10');
	});

	it('escribe la fecha suelta con año', () => {
		expect(date(ts)).toBe('11/06/2026');
	});
});

describe('mb', () => {
	it('salta a GB al pasar los 1024 MB', () => {
		expect(mb(512)).toBe('512 MB');
		expect(mb(2150)).toBe('2,1 GB');
	});
});

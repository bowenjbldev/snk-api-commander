/**
 * Qué clase de cosa es cada resultado. Va en color además de en palabra: con cuarenta filas
 * mezcladas el tipo se reconoce antes por el tono de la versalita que leyéndola. Por eso es un
 * enum cerrado y no una cadena — el color sale del valor, y un tipo inventado no tendría ninguno.
 */
export enum CommandKind {
	Navigate = 'Navegar',
	Instance = 'Instancia',
	Client = 'Cliente',
	Invoice = 'Factura',
	Control = 'Control'
}

export interface Command {
	id: string;
	kind: CommandKind;
	label: string;
	/** Dato del sistema que ayuda a distinguir: slug, importe, estado. */
	hint?: string;
	run: () => void;
}

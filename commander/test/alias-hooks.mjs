// El runner de Node no lee los `paths` de tsconfig ni los alias de Vite. Este hook
// los resuelve para que los módulos de `model/` puedan usar los mismos imports que
// el resto del código en vez de rutas relativas solo para los tests.
import { register } from 'node:module';
import { pathToFileURL } from 'node:url';

register('./alias-resolver.mjs', pathToFileURL('./test/'));

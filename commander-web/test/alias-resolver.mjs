import { existsSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { resolve as resolvePath } from 'node:path';

const ALIASES = {
  '@app/': 'src/app/',
  '@features/': 'src/features/',
  '@shared/': 'src/shared/',
  '@services/': 'src/services/',
};

export function resolve(specifier, context, next) {
  for (const [alias, target] of Object.entries(ALIASES)) {
    if (!specifier.startsWith(alias)) continue;
    let file = resolvePath(process.cwd(), target + specifier.slice(alias.length));
    // Los imports de TS omiten la extensión; el runtime la necesita.
    if (!existsSync(file)) {
      for (const ext of ['.ts', '.tsx', '.js', '/index.ts']) {
        if (existsSync(file + ext)) {
          file += ext;
          break;
        }
      }
    }
    return next(pathToFileURL(file).href, context);
  }
  return next(specifier, context);
}

export { fileURLToPath };

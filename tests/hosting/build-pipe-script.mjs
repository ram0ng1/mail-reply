// Gera o script de pipe exatamente como o admin mostra, a partir do mesmo
// código-fonte (js/src/admin/utils/buildPhpScript.ts). Node 22.6+.
//
//   node --experimental-strip-types build-pipe-script.mjs <url> <segredo> > forward-to-flarum.php
import { pathToFileURL } from 'node:url';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const mod = await import(pathToFileURL(resolve(here, '../../js/src/admin/utils/buildPhpScript.ts')).href);
process.stdout.write(mod.default(process.argv[2], process.argv[3]));

// Copies the Vite build into the Laravel app:
//   dist/assets/*   -> backend/public/assets/   (served directly by the web server, cacheable)
//   dist/index.html -> backend/resources/spa/index.html (served by Laravel, so the preview gate applies)
import { cpSync, rmSync, mkdirSync, existsSync, readdirSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const dist = resolve(here, '../dist');
const backend = resolve(here, '../../backend');
const assets = resolve(backend, 'public/assets');

if (!existsSync(resolve(dist, 'index.html'))) throw new Error('Run vite build first');
rmSync(assets, { recursive: true, force: true });
cpSync(resolve(dist, 'assets'), assets, { recursive: true });
for (const f of readdirSync(dist)) {
  if (f !== 'assets' && f !== 'index.html') cpSync(resolve(dist, f), resolve(backend, 'public', f), { recursive: true });
}
mkdirSync(resolve(backend, 'resources/spa'), { recursive: true });
cpSync(resolve(dist, 'index.html'), resolve(backend, 'resources/spa/index.html'));
console.log('Installed build into', backend);

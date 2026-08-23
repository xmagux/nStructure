import { copyFile, mkdir } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';

const assets = [
    ['node_modules/preline/dist/preline.js', 'public/assets/vendor/preline.js'],
    ['node_modules/lucide/dist/umd/lucide.min.js', 'public/assets/vendor/lucide.min.js'],
    ['node_modules/cytoscape/dist/cytoscape.min.js', 'public/assets/vendor/cytoscape.min.js'],
    ['node_modules/konva/konva.min.js', 'public/assets/vendor/konva.min.js'],
];

for (const [source, destination] of assets) {
    const target = resolve(destination);
    await mkdir(dirname(target), { recursive: true });
    await copyFile(resolve(source), target);
}

process.stdout.write(`Copied ${assets.length} browser assets.\n`);

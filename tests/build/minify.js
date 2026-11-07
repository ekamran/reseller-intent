/**
 * Builds the .min siblings of the front-end files. The readable files are
 * the source of truth and ship as well: SCRIPT_DEBUG serves them. Run
 * `npm run minify` after editing any of them; CI fails when a committed
 * .min copy no longer matches what this script produces.
 *
 *   node tests/build/minify.js                 writes next to the sources
 *   MINIFY_OUT=/some/dir node tests/build/minify.js   writes there instead
 */
const fs = require('fs');
const path = require('path');
const { minify } = require('terser');
const { transform } = require('lightningcss');

const ROOT = path.join(__dirname, '../..');
const OUT = process.env.MINIFY_OUT || ROOT;
const FILES = [
	'assets/js/tracker.js',
	'assets/js/widget.js',
	'assets/js/phone.js',
	'assets/css/widget.css',
];

(async () => {
	for (const rel of FILES) {
		const source = fs.readFileSync(path.join(ROOT, rel), 'utf8');
		const target = rel.replace(/\.(js|css)$/, '.min.$1');
		let code;

		if (rel.endsWith('.js')) {
			// The sources are ES5 on purpose (old storefront themes, old
			// phones); the output stays ES5 too.
			const result = await minify(source, {
				ecma: 5,
				compress: { passes: 2 },
				mangle: true,
				format: { comments: false },
			});
			code = result.code;
		} else {
			code = transform({
				filename: rel,
				code: Buffer.from(source),
				minify: true,
			}).code.toString();
		}

		fs.mkdirSync(path.dirname(path.join(OUT, target)), { recursive: true });
		fs.writeFileSync(path.join(OUT, target), code.endsWith('\n') ? code : code + '\n');
		console.log(`${target}: ${source.length} -> ${code.length} bytes`);
	}
})().catch((error) => {
	console.error(error);
	process.exit(1);
});

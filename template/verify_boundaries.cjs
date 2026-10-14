const fs = require('fs');
const path = require('path');
const dir = 'd:/AI/market-template/template/stitch_full_page_ui_design';

const contentOfMatchers = fs.readFileSync('d:/AI/market-template/template/test_all_matchers.cjs', 'utf8');
// evaluate configs safely
const fn = new Function('require', 'module', 'exports', contentOfMatchers + '; return configs;');
const configs = fn(require, {}, {});

for (const [file, cfg] of Object.entries(configs)) {
  const content = fs.readFileSync(path.join(dir, file), 'utf8');
  const m = content.match(cfg.find);
  if (m) {
    console.log(`\n================ ${file} (Match len: ${m[0].length}) ================`);
    console.log('START: ' + m[0].substring(0, 60).replace(/\s+/g, ' '));
    console.log('END:   ' + m[0].substring(m[0].length - 60).replace(/\s+/g, ' '));
    const idx = content.indexOf(m[0]);
    const after = content.substring(idx + m[0].length, idx + m[0].length + 80).replace(/\s+/g, ' ');
    console.log('AFTER: ' + after);
  }
}

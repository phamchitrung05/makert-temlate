const fs = require('fs');
const content = fs.readFileSync('d:/AI/market-template/template/stitch_full_page_ui_design/analytics.html', 'utf8');

const asides = (content.match(/<aside/g) || []).length;
const headers = (content.match(/<header/g) || []).length;
const mains = (content.match(/<main/g) || []).length;

console.log('asides:', asides, 'headers:', headers, 'mains:', mains);
// Find all <main tags and their line numbers
const lines = content.split('\n');
lines.forEach((l, i) => {
  if (l.includes('<main') || l.includes('</main>') || l.includes('<aside') || l.includes('</aside>')) {
    console.log(`Line ${i+1}: ${l.substring(0, 80)}`);
  }
});

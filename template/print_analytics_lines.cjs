const fs = require('fs');
const content = fs.readFileSync('d:/AI/market-template/template/stitch_full_page_ui_design/analytics.html', 'utf8');
const lines = content.split('\n');
console.log(lines.slice(126, 210).join('\n'));

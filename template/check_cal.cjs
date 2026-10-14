const fs = require('fs');
const content = fs.readFileSync('d:/AI/market-template/template/stitch_full_page_ui_design/calendar.html', 'utf8');
const idx = content.indexOf('<!-- Header Bar -->');
console.log(content.substring(idx, idx + 1800));

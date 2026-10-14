const fs = require('fs');
const content = fs.readFileSync('d:/AI/market-template/template/stitch_full_page_ui_design/analytics.html', 'utf8');
const idx1 = content.indexOf('<!-- Breadcrumb & Top Bar Control -->');
const idx2 = content.indexOf('<!-- 4 Major KPI Bento Grid -->');
console.log('idx1:', idx1, 'idx2:', idx2);
console.log(content.substring(idx1, idx2));

const fs = require('fs');
const content = fs.readFileSync('d:/AI/market-template/template/stitch_full_page_ui_design/invoices.html', 'utf8');
const idx = content.indexOf('<!-- Sub-header / Breadcrumb & View Title Bar -->');
const idxNext = content.indexOf('<!-- KPI Metrics Strip -->');
console.log('Invoices header block len:', idxNext - idx);
console.log(content.substring(idx, idxNext));

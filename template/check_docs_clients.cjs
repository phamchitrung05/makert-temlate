const fs = require('fs');

['documents.html', 'clients.html'].forEach(f => {
  const content = fs.readFileSync('d:/AI/market-template/template/stitch_full_page_ui_design/' + f, 'utf8');
  console.log('=== ' + f + ' ===');
  const m = content.match(/<!-- (?:Breadcrumb & Page Header|Header Section) -->([\s\S]*?)<!-- (?:4 KPI Metrics|Top KPI Summary Cards) -->/i);
  if (m) {
    console.log('Len:', m[0].length);
    console.log(m[0]);
  }
});

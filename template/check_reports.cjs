const fs = require('fs');

['reports.html', 'forecast.html'].forEach(f => {
  const content = fs.readFileSync('d:/AI/market-template/template/stitch_full_page_ui_design/' + f, 'utf8');
  console.log('=== ' + f + ' ===');
  const idx = content.indexOf('<!-- Top Command & Header Bar -->');
  const idxNext = content.indexOf('<!-- KPI Metric Band -->');
  console.log('Len:', idxNext - idx);
  console.log(content.substring(idx, idxNext));
});

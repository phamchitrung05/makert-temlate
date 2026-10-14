const fs = require('fs');
const content = fs.readFileSync('d:/AI/market-template/template/stitch_full_page_ui_design/analytics.html', 'utf8');
const m = content.match(/<div class="content-fixed-container[^"]*">([\s\S]*?)<\/main>/i);
if (m) {
  console.log(m[1].substring(0, 1500));
}

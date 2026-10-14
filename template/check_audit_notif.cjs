const fs = require('fs');

['audit-log.html', 'notifications.html'].forEach(f => {
  const content = fs.readFileSync('d:/AI/market-template/template/stitch_full_page_ui_design/' + f, 'utf8');
  console.log('=== ' + f + ' ===');
  const m = content.match(/<div class="content-fixed-container[^"]*">([\s\S]*?)<\/main>/i);
  if (m) {
    console.log(m[1].substring(0, 1500));
  }
});

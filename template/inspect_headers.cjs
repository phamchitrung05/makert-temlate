const fs = require('fs');
const path = require('path');
const dir = 'd:/AI/market-template/template/stitch_full_page_ui_design';
const files = fs.readdirSync(dir).filter(f => f.endsWith('.html') && f !== 'login.html');

files.forEach(f => {
  const content = fs.readFileSync(path.join(dir, f), 'utf8');
  console.log('====================================================');
  console.log('FILE:', f);
  const m = content.match(/<div class="content-fixed-container[^"]*">([\s\S]*?)<\/main>/i);
  if (m) {
    const inner = m[1].trim();
    // print up to 500 characters
    console.log(inner.substring(0, 500));
  }
});

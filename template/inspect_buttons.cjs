const fs = require('fs');
const path = require('path');
const dir = 'd:/AI/market-template/template/stitch_full_page_ui_design';
const files = fs.readdirSync(dir).filter(f => f.endsWith('.html') && f !== 'login.html');

files.forEach(f => {
  const content = fs.readFileSync(path.join(dir, f), 'utf8');
  console.log('==================================================');
  console.log('FILE:', f);
  // find first button or button group in main
  const m = content.match(/<main[\s\S]*?>([\s\S]*?)<\/main>/i);
  if (!m) return;
  const main = m[1];
  
  // Find all buttons in the first 2500 chars of main
  const sub = main.substring(0, 2500);
  const buttons = sub.match(/<button[\s\S]*?<\/button>/gi) || [];
  console.log('Found buttons:', buttons.length);
  buttons.slice(0, 4).forEach((b, i) => {
    console.log(`Btn ${i+1}:`, b.replace(/\s+/g, ' ').substring(0, 120));
  });
});

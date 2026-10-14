const fs = require('fs');
const path = require('path');
const dir = 'd:/AI/market-template/template/stitch_full_page_ui_design';
const files = fs.readdirSync(dir).filter(f => f.endsWith('.html') && f !== 'login.html');

console.log(`Checking ${files.length} application pages...`);

let allPassed = true;
const summary = [];

files.forEach(f => {
  const content = fs.readFileSync(path.join(dir, f), 'utf8');
  
  const hasPageHeaderClass = content.includes('class="page-header flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6"');
  const hasH1 = content.includes('class="text-[26px] lg:text-[28px] font-bold text-on-surface tracking-tight leading-tight"');
  const hasEyebrow = content.includes('class="text-[12px] font-semibold text-primary uppercase tracking-wider"');
  const hasDesc = content.includes('class="text-[14px] text-on-surface-variant font-normal leading-normal mt-1 max-w-3xl"');
  
  // Extract title
  const titleMatch = content.match(/<h1[^>]*>([\s\S]*?)<\/h1>/i);
  const title = titleMatch ? titleMatch[1].replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim() : 'NO H1';
  
  const passed = hasPageHeaderClass && hasH1 && hasEyebrow && hasDesc;
  if (!passed) allPassed = false;
  
  summary.push({
    file: f,
    passed,
    title,
    checks: { hasPageHeaderClass, hasH1, hasEyebrow, hasDesc }
  });
});

console.table(summary.map(s => ({ file: s.file, passed: s.passed, title: s.title })));
console.log('\nALL 17 PAGES PASSED PERFECTLY:', allPassed);

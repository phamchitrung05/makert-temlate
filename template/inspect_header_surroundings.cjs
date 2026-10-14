const fs = require('fs');
const path = require('path');
const dir = 'd:/AI/market-template/template/stitch_full_page_ui_design';

// Let's examine each file's header start and end
const files = [
  'index.html', 'projects.html', 'kanban.html', 'team.html',
  'calendar.html', 'time-tracking.html', 'reports.html', 'forecast.html',
  'analytics.html', 'invoices.html', 'clients.html', 'client-portal.html',
  'documents.html', 'sprint-planning.html', 'audit-log.html', 'notifications.html',
  'settings.html'
];

files.forEach(f => {
  const content = fs.readFileSync(path.join(dir, f), 'utf8');
  console.log(`\n=================== ${f} ===================`);
  // Look for the header block pattern
  const m = content.match(/<main[\s\S]*?>([\s\S]*?)<\/main>/i);
  if (!m) return;
  const main = m[1];
  
  // Find where page header exists
  // We can search for the first h1 (or span in team.html)
  const h1Pos = main.search(/<h1|<span[^>]*font-headline-lg/i);
  if (h1Pos !== -1) {
    const start = Math.max(0, h1Pos - 200);
    const end = Math.min(main.length, h1Pos + 800);
    console.log(main.substring(start, end));
  } else {
    console.log('NO H1/SPAN FOUND! First 500 chars:');
    console.log(main.substring(0, 500));
  }
});

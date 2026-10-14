const fs = require('fs');

['calendar.html', 'time-tracking.html'].forEach(f => {
  const content = fs.readFileSync('d:/AI/market-template/template/stitch_full_page_ui_design/' + f, 'utf8');
  console.log('=== ' + f + ' ===');
  const m = content.match(/<!-- (?:Header Bar|Header Section) -->([\s\S]*?)<!-- (?:Calendar Controls|Live Timer Card) -->/i);
  if (m) {
    console.log('Len:', m[0].length);
    console.log(m[0]);
  }
});

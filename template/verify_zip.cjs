const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

// Use tar or powershell to list files inside zip
const output = execSync('tar -tf stitch_full_page_ui_design.zip', { cwd: 'd:/AI/market-template/template' }).toString('utf8');
const lines = output.split('\n').map(l => l.trim()).filter(Boolean);

const htmls = lines.filter(l => l.endsWith('.html') && !l.includes('/'));
const screenshots = lines.filter(l => l.startsWith('screenshots/') && l.endsWith('.png'));
const hasManifest = lines.includes('manifest.json');
const hasTemplate = lines.includes('template.json');

console.log('--- ZIP VERIFICATION SUMMARY ---');
console.log('Total entries:', lines.length);
console.log('Root HTML files:', htmls.length, htmls);
console.log('Screenshots count:', screenshots.length);
console.log('Has manifest.json:', hasManifest);
console.log('Has template.json:', hasTemplate);
console.log('Zip size:', (fs.statSync('d:/AI/market-template/template/stitch_full_page_ui_design.zip').size / (1024 * 1024)).toFixed(2), 'MB');

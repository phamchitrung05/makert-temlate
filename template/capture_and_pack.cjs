const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const targetDir = path.join(__dirname, 'stitch_full_page_ui_design');
const screenshotsDir = path.join(targetDir, 'screenshots');
if (!fs.existsSync(screenshotsDir)) {
  fs.mkdirSync(screenshotsDir, { recursive: true });
}

const manifest = JSON.parse(fs.readFileSync(path.join(targetDir, 'manifest.json'), 'utf8'));
const edgeExe = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';

console.log(`Starting screenshot capture for ${manifest.screens.length} screens...`);

manifest.screens.forEach((screen, idx) => {
  const htmlFile = path.join(targetDir, screen.path);
  const shotFile = path.join(targetDir, screen.screenshot);
  const fileUrl = `file:///${htmlFile.replace(/\\/g, '/')}`;
  
  console.log(`[${idx + 1}/${manifest.screens.length}] Capturing ${screen.slug} -> ${screen.screenshot}...`);
  try {
    const cmd = `"${edgeExe}" --headless --screenshot="${shotFile}" --window-size=1440,1024 --hide-scrollbars --virtual-time-budget=2000 "${fileUrl}"`;
    execSync(cmd, { stdio: 'ignore' });
    if (fs.existsSync(shotFile)) {
      const stats = fs.statSync(shotFile);
      console.log(`    Captured: ${(stats.size / 1024).toFixed(1)} KB`);
    } else {
      console.error(`    FAILED to create screenshot: ${shotFile}`);
    }
  } catch (err) {
    console.error(`    Error capturing ${screen.slug}:`, err.message);
  }
});

console.log('\nAll screenshots captured. Now packaging into stitch_full_page_ui_design.zip...');
const zipFile = path.join(__dirname, 'stitch_full_page_ui_design.zip');
if (fs.existsSync(zipFile)) {
  fs.unlinkSync(zipFile);
}

// Use powershell Compress-Archive
const psCmd = `powershell -NoProfile -Command "Compress-Archive -Path '${targetDir}\\*' -DestinationPath '${zipFile}' -Force"`;
console.log('Running zip compression...');
execSync(psCmd, { stdio: 'inherit' });

if (fs.existsSync(zipFile)) {
  const zipStats = fs.statSync(zipFile);
  console.log(`\nSUCCESS! Created zip: ${zipFile} (${(zipStats.size / (1024 * 1024)).toFixed(2)} MB)`);
} else {
  console.error('\nFAILED to create zip file.');
}

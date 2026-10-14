const fs = require('fs');
const path = require('path');
const file = 'd:/AI/market-template/template/stitch_full_page_ui_design/analytics.html';

const header = `<!-- Standardized Page Header -->
<div class="page-header flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
  <div class="min-w-0 flex-1">
    <div class="flex items-center gap-2 mb-1">
      <span class="text-[12px] font-semibold text-primary uppercase tracking-wider">Data Intelligence &amp; Metrics</span>
    </div>
    <div class="flex items-center gap-2.5 flex-wrap">
      <h1 class="text-[26px] lg:text-[28px] font-bold text-on-surface tracking-tight leading-tight">
        Project Analytics &amp; KPI Hub
      </h1>
      <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[12px] font-semibold bg-secondary-container/40 text-on-secondary-container">
        <span class="w-1.5 h-1.5 rounded-full bg-primary animate-pulse"></span>
        Real-time Live
      </span>
    </div>
    <p class="text-[14px] text-on-surface-variant font-normal leading-normal mt-1 max-w-3xl">
      High-frequency KPI metrics, sprint completion trajectories, bug resolution velocity, and burn-down analytics.
    </p>
  </div>
  <div class="flex items-center gap-2.5 flex-wrap flex-shrink-0 self-start md:self-center">
    <div class="relative">
      <select class="appearance-none bg-surface-container-lowest text-on-surface font-medium text-[13px] pl-3 pr-8 py-2 rounded-xl border border-outline-variant/30 shadow-xs focus:outline-none focus:ring-2 focus:ring-primary cursor-pointer transition-all">
        <option>Tất cả dự án (All Active)</option>
        <option>Fintech Banking 3.0</option>
        <option>Mobile App Redesign</option>
        <option>CRM Integration</option>
      </select>
      <span class="material-symbols-outlined absolute right-2.5 top-1/2 -translate-y-1/2 text-outline pointer-events-none text-[18px]">expand_more</span>
    </div>
    <div class="relative">
      <select class="appearance-none bg-surface-container-lowest text-on-surface font-medium text-[13px] pl-3 pr-8 py-2 rounded-xl border border-outline-variant/30 shadow-xs focus:outline-none focus:ring-2 focus:ring-primary cursor-pointer transition-all">
        <option>30 ngày gần nhất</option>
        <option>Quý này (Q4/2024)</option>
        <option>Tháng này (Nov 2024)</option>
      </select>
      <span class="material-symbols-outlined absolute right-2.5 top-1/2 -translate-y-1/2 text-outline pointer-events-none text-[18px]">calendar_today</span>
    </div>
    <button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-surface-container-lowest text-on-surface font-medium text-[13px] border border-outline-variant/30 shadow-xs hover:bg-surface-container transition-all" type="button">
      <span class="material-symbols-outlined text-[18px]">file_download</span>
      <span>Export Data</span>
    </button>
  </div>
</div>`;

let content = fs.readFileSync(file, 'utf8');
const targetRegex = /<!-- Breadcrumb & Top Bar Control -->[\s\S]*?<\/div>\s*<\/div>(?=\s*<!-- AI Intelligence Notification Banner -->)/i;
if (targetRegex.test(content)) {
  content = content.replace(targetRegex, header);
  fs.writeFileSync(file, content, 'utf8');
  console.log('SUCCESS: analytics.html updated successfully!');
} else {
  console.error('FAILED: regex target not found in analytics.html');
}

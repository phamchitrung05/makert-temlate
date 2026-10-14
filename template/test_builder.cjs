const fs = require('fs');
const path = require('path');
const dir = 'd:/AI/market-template/template/stitch_full_page_ui_design';

// Define the exact standard Page Header builder
function makeHeader({ eyebrow, title, badge, desc, actions }) {
  const badgeHtml = badge 
    ? `<span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[12px] font-semibold bg-secondary-container/40 text-on-secondary-container">${badge}</span>`
    : '';

  return `<!-- Standardized Page Header -->
<div class="page-header flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
  <div class="min-w-0 flex-1">
    <div class="flex items-center gap-2 mb-1">
      <span class="text-[12px] font-semibold text-primary uppercase tracking-wider">${eyebrow}</span>
    </div>
    <div class="flex items-center gap-2.5 flex-wrap">
      <h1 class="text-[26px] lg:text-[28px] font-bold text-on-surface tracking-tight leading-tight">
        ${title}
      </h1>
      ${badgeHtml}
    </div>
    <p class="text-[14px] text-on-surface-variant font-normal leading-normal mt-1 max-w-3xl">
      ${desc}
    </p>
  </div>
  <div class="flex items-center gap-2.5 flex-wrap flex-shrink-0 self-start md:self-center">
    ${actions}
  </div>
</div>`;
}

console.log('Builder defined successfully.');

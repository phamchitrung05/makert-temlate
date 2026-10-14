const fs = require('fs');
const path = require('path');
const dir = 'd:/AI/market-template/template/stitch_full_page_ui_design';

function makeStandardHeader({ eyebrow, title, badge, desc, actions }) {
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

const updates = [
  {
    file: 'index.html',
    header: makeStandardHeader({
      eyebrow: 'Dashboard &amp; Overview',
      title: 'Dashboard Overview',
      badge: '<span class="w-1.5 h-1.5 rounded-full bg-secondary animate-pulse"></span>Live Active',
      desc: 'Tổng quan tình hình thực hiện dự án, tiến độ công việc, năng suất đội ngũ và cảnh báo thời gian thực.',
      actions: `<button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-surface-container-lowest text-on-surface font-medium text-[13px] border border-outline-variant/30 shadow-xs hover:bg-surface-container transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">file_download</span>
  <span>Export CSV</span>
</button>
<button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-surface-container-lowest text-on-surface font-medium text-[13px] border border-outline-variant/30 shadow-xs hover:bg-surface-container transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">tune</span>
  <span>Bộ lọc</span>
</button>
<button class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-primary text-on-primary font-medium text-[13px] shadow-sm hover:bg-primary-dark transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">add</span>
  <span>+ Tạo Dự án Mới</span>
</button>`
    }),
    replace: (content, header) => {
      return content.replace(/<!-- Top Action \/ Title Header -->[\s\S]*?<\/div>\s*<\/div>(?=\s*<!-- 4 KPI Summary Cards -->)/i, header);
    }
  },
  {
    file: 'projects.html',
    header: makeStandardHeader({
      eyebrow: 'Project Management &amp; Initiatives',
      title: 'Projects Overview',
      badge: '12 Active Projects',
      desc: 'Track, manage and organize all active client deliverables and internal engineering initiatives.',
      actions: `<button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-surface-container-lowest text-on-surface font-medium text-[13px] border border-outline-variant/30 shadow-xs hover:bg-surface-container transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">file_download</span>
  <span>Export</span>
</button>
<button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-surface-container-lowest text-on-surface font-medium text-[13px] border border-outline-variant/30 shadow-xs hover:bg-surface-container transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">tune</span>
  <span>Filter</span>
</button>
<button class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-primary text-on-primary font-medium text-[13px] shadow-sm hover:bg-primary-dark transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">add_circle</span>
  <span>+ New Project</span>
</button>`
    }),
    replace: (content, header) => {
      return content.replace(/<!-- Top Action \/ Title Header -->[\s\S]*?<\/div>\s*<\/div>(?=\s*<!-- 4 KPI Summary Cards -->)/i, header);
    }
  },
  {
    file: 'kanban.html',
    header: makeStandardHeader({
      eyebrow: 'Task Management &amp; Sprints',
      title: 'Kanban Board',
      badge: '<span class="w-1.5 h-1.5 rounded-full bg-secondary"></span>Sprint 14 Active',
      desc: 'Sprint 14: Q2 Product Features &amp; Redesign • May 15 – May 29, 2025 (11 days remaining)',
      actions: `<div class="inline-flex items-center rounded-xl bg-surface-container-low p-1 border border-outline-variant/30">
  <button class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-surface-container-lowest text-primary font-medium text-[13px] shadow-xs">
    <span class="material-symbols-outlined text-[16px]">view_kanban</span>
    <span class="hidden sm:inline">Board</span>
  </button>
  <button class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-on-surface-variant hover:text-on-surface font-medium text-[13px] transition-colors">
    <span class="material-symbols-outlined text-[16px]">format_list_bulleted</span>
    <span class="hidden sm:inline">List</span>
  </button>
  <button class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-on-surface-variant hover:text-on-surface font-medium text-[13px] transition-colors">
    <span class="material-symbols-outlined text-[16px]">view_timeline</span>
    <span class="hidden sm:inline">Gantt</span>
  </button>
</div>
<button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-surface-container-lowest text-on-surface font-medium text-[13px] border border-outline-variant/30 shadow-xs hover:bg-surface-container transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">tune</span>
  <span class="hidden sm:inline">Filter</span>
</button>
<button class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-primary text-on-primary font-medium text-[13px] shadow-sm hover:bg-primary-dark transition-all" id="quick-add-btn" type="button">
  <span class="material-symbols-outlined text-[18px]">add</span>
  <span>+ New Task</span>
</button>`
    }),
    replace: (content, header) => {
      return content.replace(/<!-- Top Banner & Controls -->[\s\S]*?<\/div>\s*<\/div>(?=\s*<!-- Filter, Team Avatars)/i, header);
    }
  },
  {
    file: 'team.html',
    header: makeStandardHeader({
      eyebrow: 'Team &amp; Access Control',
      title: 'Team Members &amp; Roles',
      badge: '14 Members',
      desc: 'Manage team members, roles, department allocations, and real-time availability.',
      actions: `<button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-surface-container-lowest text-on-surface font-medium text-[13px] border border-outline-variant/30 shadow-xs hover:bg-surface-container transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">file_download</span>
  <span>Export CSV</span>
</button>
<button class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-primary text-on-primary font-medium text-[13px] shadow-sm hover:bg-primary-dark transition-all" id="btn-open-invite" type="button">
  <span class="material-symbols-outlined text-[18px]">person_add</span>
  <span>+ Invite Member</span>
</button>`
    }),
    replace: (content, header) => {
      return content.replace(/<!-- Page Header Section -->[\s\S]*?<\/div>\s*<\/div>(?=\s*<!-- Team Metrics Grid)/i, header);
    }
  },
  {
    file: 'calendar.html',
    header: makeStandardHeader({
      eyebrow: 'Schedule &amp; Timelines',
      title: 'Calendar &amp; Deadlines',
      badge: '<span class="w-1.5 h-1.5 rounded-full bg-secondary"></span>May 2025',
      desc: 'Schedule milestone deliverables, team sprint schedules, meetings, and project deadlines.',
      actions: `<div class="inline-flex items-center bg-surface-container-lowest rounded-xl p-1 shadow-xs border border-outline-variant/30">
  <button class="px-3 py-1.5 rounded-lg text-[13px] font-medium text-on-surface bg-surface-container-high transition-colors" type="button">Month</button>
  <button class="px-3 py-1.5 rounded-lg text-[13px] font-medium text-on-surface-variant hover:text-on-surface transition-colors" type="button">Week</button>
  <button class="px-3 py-1.5 rounded-lg text-[13px] font-medium text-on-surface-variant hover:text-on-surface transition-colors" type="button">Day</button>
</div>
<button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-surface-container-lowest text-on-surface font-medium text-[13px] border border-outline-variant/30 shadow-xs hover:bg-surface-container transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">file_download</span>
  <span>Export</span>
</button>
<button class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-primary text-on-primary font-medium text-[13px] shadow-sm hover:bg-primary-dark transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">add</span>
  <span>+ New Event</span>
</button>`
    }),
    replace: (content, header) => {
      return content.replace(/<!-- Header Bar -->[\s\S]*?<\/div>\s*<\/div>(?=\s*<!-- Calendar Controls)/i, header);
    }
  },
  {
    file: 'time-tracking.html',
    header: makeStandardHeader({
      eyebrow: 'Time &amp; Resource Monitoring',
      title: 'Time Tracking &amp; Timesheets',
      badge: '<span class="w-1.5 h-1.5 rounded-full bg-secondary animate-pulse"></span>Auto-Tracking On',
      desc: 'Log hours, monitor real-time activity across active sprints, and approve team timesheets.',
      actions: `<button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-surface-container-lowest text-on-surface font-medium text-[13px] border border-outline-variant/30 shadow-xs hover:bg-surface-container transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">download</span>
  <span>Export Timesheet</span>
</button>
<button class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-primary text-on-primary font-medium text-[13px] shadow-sm hover:bg-primary-dark transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">add_circle</span>
  <span>+ Manual Time Log</span>
</button>`
    }),
    replace: (content, header) => {
      return content.replace(/<!-- Header Section -->[\s\S]*?<\/div>\s*<\/div>(?=\s*<!-- Live Timer Card -->)/i, header);
    }
  },
  {
    file: 'reports.html',
    header: makeStandardHeader({
      eyebrow: 'Executive Reports &amp; Insights',
      title: 'Reports &amp; Performance Analytics',
      badge: '<span class="w-1.5 h-1.5 rounded-full bg-secondary"></span>Q2 Performance',
      desc: 'Comprehensive insights into sprint velocity, team workload efficiency, project budgets, and delivery forecasts.',
      actions: `<button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-surface-container-lowest text-on-surface font-medium text-[13px] border border-outline-variant/30 shadow-xs hover:bg-surface-container transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">calendar_today</span>
  <span>Q2 (Apr - Jun 2025)</span>
</button>
<button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-surface-container-lowest text-on-surface font-medium text-[13px] border border-outline-variant/30 shadow-xs hover:bg-surface-container transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">file_download</span>
  <span>Export PDF</span>
</button>
<button class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-primary text-on-primary font-medium text-[13px] shadow-sm hover:bg-primary-dark transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">insights</span>
  <span>+ Generate Report</span>
</button>`
    }),
    replace: (content, header) => {
      return content.replace(/<!-- Top Command & Header Bar -->[\s\S]*?<\/div>\s*<\/div>(?=\s*<\/div>\s*<!-- KPI Metric Band -->)/i, header);
    }
  },
  {
    file: 'forecast.html',
    header: makeStandardHeader({
      eyebrow: 'Resource Planning &amp; Capacity',
      title: 'Forecast &amp; Resource Analytics',
      badge: '<span class="w-1.5 h-1.5 rounded-full bg-secondary"></span>Predictive AI Model',
      desc: 'AI-driven delivery forecasting, workload balance simulation, and cross-department capacity optimization.',
      actions: `<button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-surface-container-lowest text-on-surface font-medium text-[13px] border border-outline-variant/30 shadow-xs hover:bg-surface-container transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">tune</span>
  <span>Kịch bản: Q3 Baseline</span>
</button>
<button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-surface-container-lowest text-on-surface font-medium text-[13px] border border-outline-variant/30 shadow-xs hover:bg-surface-container transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">file_download</span>
  <span>Export Forecast</span>
</button>
<button class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-primary text-on-primary font-medium text-[13px] shadow-sm hover:bg-primary-dark transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">play_arrow</span>
  <span>+ Chạy Mô phỏng</span>
</button>`
    }),
    replace: (content, header) => {
      return content.replace(/<!-- Top Command & Header Bar -->[\s\S]*?<\/div>\s*<\/div>(?=\s*<\/div>\s*<!-- KPI Metric Band -->)/i, header);
    }
  },
  {
    file: 'analytics.html',
    header: makeStandardHeader({
      eyebrow: 'Data Intelligence &amp; Metrics',
      title: 'Project Analytics &amp; KPI Hub',
      badge: '<span class="w-1.5 h-1.5 rounded-full bg-primary animate-pulse"></span>Real-time Live',
      desc: 'High-frequency KPI metrics, sprint completion trajectories, bug resolution velocity, and burn-down analytics.',
      actions: `<div class="relative">
  <select class="appearance-none bg-surface-container-lowest text-on-surface font-medium text-[13px] pl-3 pr-8 py-2 rounded-xl border border-outline-variant/30 shadow-xs focus:outline-none focus:ring-2 focus:ring-primary cursor-pointer transition-all">
    <option>Tất cả dự án (All Active)</option>
    <option>Fintech Banking 3.0</option>
    <option>Mobile App Redesign</option>
    <option>CRM Integration</option>
  </select>
  <span class="material-symbols-outlined absolute right-2.5 top-1/2 -translate-y-1/2 text-outline pointer-events-none text-[18px]">expand_more</span>
</div>
<button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-surface-container-lowest text-on-surface font-medium text-[13px] border border-outline-variant/30 shadow-xs hover:bg-surface-container transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">file_download</span>
  <span>Export Data</span>
</button>`
    }),
    replace: (content, header) => {
      return content.replace(/<!-- Breadcrumb & Top Bar Control -->[\s\S]*?<!-- Action Group -->[\s\S]*?<\/div>\s*<\/div>(?=\s*<!-- 4 Major KPI Bento Grid -->)/i, header);
    }
  },
  {
    file: 'invoices.html',
    header: makeStandardHeader({
      eyebrow: 'Finance &amp; Revenue Management',
      title: 'Hóa đơn &amp; Doanh thu',
      badge: 'Invoices &amp; Billing',
      desc: 'Quản lý dòng tiền, phát hành hóa đơn VAT điện tử, kiểm soát công nợ đối tác đa quốc gia.',
      actions: `<button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-surface-container-lowest text-on-surface font-medium text-[13px] border border-outline-variant/30 shadow-xs hover:bg-surface-container transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">tune</span>
  <span>Bộ lọc</span>
</button>
<button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-surface-container-lowest text-on-surface font-medium text-[13px] border border-outline-variant/30 shadow-xs hover:bg-surface-container transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">file_download</span>
  <span>Xuất báo cáo VAT</span>
</button>
<button class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-primary text-on-primary font-medium text-[13px] shadow-sm hover:bg-primary-dark transition-all" id="btn-create-invoice" type="button">
  <span class="material-symbols-outlined text-[18px]">add_circle</span>
  <span>+ Tạo hóa đơn mới</span>
</button>`
    }),
    replace: (content, header) => {
      return content.replace(/<!-- Sub-header \/ Breadcrumb & View Title Bar -->[\s\S]*?<\/div>\s*<\/div>\s*<\/div>(?=\s*<!-- KPI Metrics Strip -->)/i, header);
    }
  },
  {
    file: 'clients.html',
    header: makeStandardHeader({
      eyebrow: 'Partners &amp; CRM Directory',
      title: 'Clients &amp; Partners Directory',
      badge: '24 Active Accounts',
      desc: 'Manage client portfolios, linked deliverables, contracts, invoice balances, and primary point-of-contacts in real time.',
      actions: `<button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-surface-container-lowest text-on-surface font-medium text-[13px] border border-outline-variant/30 shadow-xs hover:bg-surface-container transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">file_download</span>
  <span>Export Directory</span>
</button>
<button class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-primary text-on-primary font-medium text-[13px] shadow-sm hover:bg-primary-dark transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">person_add</span>
  <span>+ Add New Client</span>
</button>`
    }),
    replace: (content, header) => {
      return content.replace(/<!-- Header Section -->[\s\S]*?<\/div>\s*<\/div>(?=\s*<!-- Top KPI Summary Cards -->)/i, header);
    }
  },
  {
    file: 'client-portal.html',
    header: makeStandardHeader({
      eyebrow: 'Client Portal &amp; Partner Access',
      title: 'Cổng Thông tin Đối tác (Client Portal)',
      badge: '<span class="w-1.5 h-1.5 rounded-full bg-secondary animate-pulse"></span>Sprint 14 Active',
      desc: 'Hệ sinh thái Ngân hàng Số &amp; Core Banking 3.0 • Theo dõi tiến độ hợp đồng, phê duyệt deliverables và nghiệm thu thanh toán.',
      actions: `<button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-surface-container-lowest text-on-surface font-medium text-[13px] border border-outline-variant/30 shadow-xs hover:bg-surface-container transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">file_download</span>
  <span>Tải SLA Đối tác</span>
</button>
<button class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-primary text-on-primary font-medium text-[13px] shadow-sm hover:bg-primary-dark transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">verified</span>
  <span>+ Phê duyệt Milestone</span>
</button>`
    }),
    replace: (content, header) => {
      return content.replace(/<!-- Top Breadcrumb & Quick Portal Status Indicator -->/i, header);
    }
  },
  {
    file: 'documents.html',
    header: makeStandardHeader({
      eyebrow: 'Knowledge Management &amp; Assets',
      title: 'Documents &amp; Knowledge Base',
      badge: '142 Files • 8 Workspaces',
      desc: 'Centralized repository for project specifications, PRDs, sprint documentations, client deliverables, and company wikis.',
      actions: `<button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-surface-container-lowest text-on-surface font-medium text-[13px] border border-outline-variant/30 shadow-xs hover:bg-surface-container transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">tune</span>
  <span>Filter Files</span>
</button>
<button class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-primary text-on-primary font-medium text-[13px] shadow-sm hover:bg-primary-dark transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">upload_file</span>
  <span>+ Upload Document</span>
</button>`
    }),
    replace: (content, header) => {
      return content.replace(/<!-- Breadcrumb & Page Header -->[\s\S]*?<\/div>\s*<\/div>(?=\s*<\/div>\s*<!-- 4 KPI Metrics -->)/i, header);
    }
  },
  {
    file: 'sprint-planning.html',
    header: makeStandardHeader({
      eyebrow: 'Agile Development &amp; Sprints',
      title: 'Lập kế hoạch Sprint &amp; Backlog',
      badge: '<span class="w-1.5 h-1.5 rounded-full bg-secondary"></span>Planning Active',
      desc: 'Ước lượng story points, phân bổ năng lực lập trình viên, sắp xếp backlog và khởi chạy chu kỳ phát triển mới.',
      actions: `<button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-surface-container-lowest text-on-surface font-medium text-[13px] border border-outline-variant/30 shadow-xs hover:bg-surface-container transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">style</span>
  <span>Planning Poker</span>
</button>
<button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-surface-container-lowest text-on-surface font-medium text-[13px] border border-outline-variant/30 shadow-xs hover:bg-surface-container transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">tune</span>
  <span>Cấu hình Sprint</span>
</button>
<button class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-primary text-on-primary font-medium text-[13px] shadow-sm hover:bg-primary-dark transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">play_circle</span>
  <span>+ Khởi chạy Sprint mới</span>
</button>`
    }),
    replace: (content, header) => {
      return content.replace(/<!-- Top Context Navigation & Meta Badges -->[\s\S]*?<\/div>\s*<\/div>(?=\s*<!-- Sprint Header Control)/i, header);
    }
  },
  {
    file: 'audit-log.html',
    header: makeStandardHeader({
      eyebrow: 'Compliance &amp; Security Trail',
      title: 'Nhật ký Hoạt động &amp; An ninh',
      badge: 'Audit Trail SOC-2',
      desc: 'Ghi nhận toàn bộ thao tác hệ thống, thay đổi dữ liệu, đăng nhập và phân quyền bảo mật chuẩn SOC-2 &amp; ISO 27001.',
      actions: `<button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-surface-container-lowest text-on-surface font-medium text-[13px] border border-outline-variant/30 shadow-xs hover:bg-surface-container transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">tune</span>
  <span>Bộ lọc nâng cao</span>
</button>
<button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-surface-container-lowest text-on-surface font-medium text-[13px] border border-outline-variant/30 shadow-xs hover:bg-surface-container transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">file_download</span>
  <span>Xuất CSV</span>
</button>
<button class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-primary text-on-primary font-medium text-[13px] shadow-sm hover:bg-primary-dark transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">security</span>
  <span>+ Cấu hình Retention</span>
</button>`
    }),
    replace: (content, header) => {
      return content.replace(/<!-- Header Row -->[\s\S]*?<\/div>\s*<\/div>(?=\s*<\/div>\s*<!-- KPI Summary Cards)/i, header);
    }
  },
  {
    file: 'notifications.html',
    header: makeStandardHeader({
      eyebrow: 'Communications &amp; Alerts',
      title: 'Trung tâm Thông báo &amp; Hoạt động',
      badge: '<span class="w-1.5 h-1.5 rounded-full bg-secondary animate-pulse"></span>12 chưa đọc',
      desc: 'Cập nhật thông báo hệ thống, nhắc nhở hạn chót sprint, bình luận nhiệm vụ và yêu cầu phê duyệt công việc.',
      actions: `<button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-surface-container-lowest text-on-surface font-medium text-[13px] border border-outline-variant/30 shadow-xs hover:bg-surface-container transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">filter_list</span>
  <span>Chưa đọc (12)</span>
</button>
<button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-surface-container-lowest text-on-surface font-medium text-[13px] border border-outline-variant/30 shadow-xs hover:bg-surface-container transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">done_all</span>
  <span>Đánh dấu đã đọc</span>
</button>
<button class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-primary text-on-primary font-medium text-[13px] shadow-sm hover:bg-primary-dark transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">settings</span>
  <span>Cài đặt thông báo</span>
</button>`
    }),
    replace: (content, header) => {
      return content.replace(/<!-- Breadcrumb & Top Utility Row -->[\s\S]*?<!-- Page Header Area -->[\s\S]*?<\/div>\s*<\/div>(?=\s*<\/div>\s*<!-- 2-Pane Inbox Layout Structure -->)/i, header);
    }
  },
  {
    file: 'settings.html',
    header: makeStandardHeader({
      eyebrow: 'Workspace &amp; System Config',
      title: 'Settings &amp; Workspace Preferences',
      badge: 'FlowTask Enterprise',
      desc: 'Manage your personal profile, team permissions, security settings, integrations, and Pro subscription billing.',
      actions: `<button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-surface-container text-on-surface font-medium text-[13px] shadow-xs hover:bg-surface-container-high transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">close</span>
  <span>Discard</span>
</button>
<button class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-primary text-on-primary font-medium text-[13px] shadow-sm hover:bg-primary-dark transition-all" type="button">
  <span class="material-symbols-outlined text-[18px]">check</span>
  <span>Save Changes</span>
</button>`
    }),
    replace: (content, header) => {
      return content.replace(/<!-- Page Header & Global Actions Bar -->[\s\S]*?<\/div>\s*<\/div>\s*<\/div>(?=\s*<!-- Main Content Layout)/i, header);
    }
  }
];

// Perform updates
let successCount = 0;
updates.forEach(({ file, header, replace }) => {
  const filePath = path.join(dir, file);
  const content = fs.readFileSync(filePath, 'utf8');
  const newContent = replace(content, header);
  if (newContent !== content) {
    fs.writeFileSync(filePath, newContent, 'utf8');
    console.log(`SUCCESS: ${file} standardized.`);
    successCount++;
  } else {
    console.error(`FAILED: ${file} regex did not match or content unchanged.`);
  }
});

console.log(`\nUpdated ${successCount} / ${updates.length} files.`);

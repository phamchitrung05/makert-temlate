const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const targetDir = path.join(__dirname, 'stitch_full_page_ui_design');

// 1. Write manifest.json & template.json
const manifestData = {
  schema_version: 1,
  entry_screen: "home",
  screens: [
    {
      slug: "home",
      title: "Trang chủ",
      path: "index.html",
      screenshot: "screenshots/home.png"
    },
    {
      slug: "login",
      title: "Đăng nhập",
      path: "login.html",
      screenshot: "screenshots/login.png"
    },
    {
      slug: "forecast",
      title: "Dự báo & Báo cáo",
      path: "forecast.html",
      screenshot: "screenshots/forecast.png"
    },
    {
      slug: "settings",
      title: "Cài đặt hệ thống",
      path: "settings.html",
      screenshot: "screenshots/settings.png"
    },
    {
      slug: "kanban",
      title: "Bảng Kanban",
      path: "kanban.html",
      screenshot: "screenshots/kanban.png"
    },
    {
      slug: "projects",
      title: "Quản lý Dự án",
      path: "projects.html",
      screenshot: "screenshots/projects.png"
    },
    {
      slug: "calendar",
      title: "Lịch & Deadline",
      path: "calendar.html",
      screenshot: "screenshots/calendar.png"
    },
    {
      slug: "time-tracking",
      title: "Theo dõi thời gian",
      path: "time-tracking.html",
      screenshot: "screenshots/time-tracking.png"
    },
    {
      slug: "reports",
      title: "Báo cáo hiệu suất",
      path: "reports.html",
      screenshot: "screenshots/reports.png"
    },
    {
      slug: "team",
      title: "Thành viên & Đội ngũ",
      path: "team.html",
      screenshot: "screenshots/team.png"
    },
    {
      slug: "clients",
      title: "Khách hàng & Đối tác",
      path: "clients.html",
      screenshot: "screenshots/clients.png"
    },
    {
      slug: "client-portal",
      title: "Cổng thông tin Khách hàng",
      path: "client-portal.html",
      screenshot: "screenshots/client-portal.png"
    },
    {
      slug: "documents",
      title: "Tài liệu & Tri thức",
      path: "documents.html",
      screenshot: "screenshots/documents.png"
    },
    {
      slug: "invoices",
      title: "Hóa đơn & Doanh thu",
      path: "invoices.html",
      screenshot: "screenshots/invoices.png"
    },
    {
      slug: "sprint-planning",
      title: "Kế hoạch Sprint & Backlog",
      path: "sprint-planning.html",
      screenshot: "screenshots/sprint-planning.png"
    },
    {
      slug: "audit-log",
      title: "Nhật ký Hoạt động & An ninh",
      path: "audit-log.html",
      screenshot: "screenshots/audit-log.png"
    },
    {
      slug: "notifications",
      title: "Trung tâm Thông báo",
      path: "notifications.html",
      screenshot: "screenshots/notifications.png"
    },
    {
      slug: "analytics",
      title: "Phân tích Nâng cao",
      path: "analytics.html",
      screenshot: "screenshots/analytics.png"
    }
  ]
};

fs.writeFileSync(path.join(targetDir, 'manifest.json'), JSON.stringify(manifestData, null, 2), 'utf8');
fs.writeFileSync(path.join(targetDir, 'template.json'), JSON.stringify(manifestData, null, 2), 'utf8');
console.log('manifest.json & template.json written.');

// 2. Standardized CSS in style.css
const styleCssContent = `/* FlowTask - Vibrant SaaS Productivity Design System Styles */

@layer base {
  html,
  body {
    margin: 0;
    padding: 0;
  }
  body {
    overscroll-behavior: none;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
  }
  main > :first-child {
    margin-top: 0 !important;
  }
  main > :last-child {
    margin-bottom: 0 !important;
  }
}

/* Custom Scrollbars */
::-webkit-scrollbar {
  width: 6px;
  height: 6px;
}
::-webkit-scrollbar-track {
  background: transparent;
}
::-webkit-scrollbar-thumb {
  background: rgba(115, 118, 134, 0.2);
  border-radius: 9999px;
}
::-webkit-scrollbar-thumb:hover {
  background: rgba(115, 118, 134, 0.4);
}

/* Glassmorphism & Ambient Shadows */
.glass-panel {
  background: rgba(255, 255, 255, 0.85);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
}

.ambient-shadow {
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04), 0 4px 12px rgba(15, 23, 42, 0.03);
}

.floating-card {
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.floating-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 24px rgba(37, 99, 235, 0.08), 0 2px 6px rgba(15, 23, 42, 0.04);
}

button, a {
  transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
}

/* ========================================================
   Requirement 4: Sidebar Menu Zero-Jumping & Zero-Flickering
   Both active and inactive items have the EXACT SAME typography,
   height, line-height, and padding.
   ======================================================== */
aside nav {
  display: flex !important;
  flex-direction: column !important;
  gap: 2px !important;
}

aside nav a {
  display: flex !important;
  align-items: center !important;
  height: 40px !important;
  min-height: 40px !important;
  max-height: 40px !important;
  padding: 0 14px !important;
  border-radius: 12px !important;
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
  font-size: 14px !important;
  line-height: 20px !important;
  font-weight: 500 !important;
  white-space: nowrap !important;
  color: #434655 !important;
  text-decoration: none !important;
  box-sizing: border-box !important;
  transition: background-color 0.12s ease, color 0.12s ease !important;
}

aside nav a:hover {
  background-color: #e5eeff !important;
  color: #0b1c30 !important;
}

aside nav a[aria-current="page"],
aside nav a.active {
  font-weight: 600 !important;
  background-color: #2563eb !important;
  color: #ffffff !important;
  box-shadow: 0 1px 3px rgba(37, 99, 235, 0.25) !important;
}

aside nav a span.material-symbols-outlined {
  font-size: 20px !important;
  width: 20px !important;
  height: 20px !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  flex-shrink: 0 !important;
  margin-right: 12px !important;
  color: inherit !important;
}

aside nav a span.nav-text {
  font-size: 14px !important;
  line-height: 20px !important;
  font-weight: inherit !important;
  color: inherit !important;
  white-space: nowrap !important;
  overflow: hidden !important;
  text-overflow: ellipsis !important;
}

/* ========================================================
   Requirement 2: Standardized Content Container
   All pages boxed in a fixed max-w-[1600px] container
   ======================================================== */
.content-fixed-container {
  width: 100% !important;
  max-width: 1600px !important;
  margin-left: auto !important;
  margin-right: auto !important;
  box-sizing: border-box !important;
}
`;

fs.writeFileSync(path.join(targetDir, 'assets', 'css', 'style.css'), styleCssContent, 'utf8');
console.log('assets/css/style.css written.');

// 3. Update main.js (NO DOM manipulation on aside nav)
const mainJsContent = `/**
 * FlowTask - Vibrant SaaS Productivity Interactive Logic
 */

document.addEventListener('DOMContentLoaded', () => {
  // User Profile Dropdown
  const profileContainer = document.getElementById('user-profile-trigger') ||
                           document.querySelector('header .flex.items-center.gap-space-sm.pl-space-sm') || 
                           document.querySelector('header .flex.items-center.gap-space-sm.pl-space-xs') ||
                           document.querySelector('header div.flex.items-center.gap-3.pl-1');
  if (profileContainer) {
    profileContainer.classList.add('relative', 'cursor-pointer');
    
    let dropdown = document.getElementById('profile-dropdown');
    if (!dropdown) {
      dropdown = document.createElement('div');
      dropdown.id = 'profile-dropdown';
      dropdown.className = 'hidden absolute right-0 top-12 w-56 bg-surface-container-lowest rounded-2xl shadow-xl border border-outline-variant/30 py-2 z-50 transition-all';
      dropdown.innerHTML = \`
        <div class="px-4 py-2.5 border-b border-surface-container">
          <p class="font-label-md text-on-surface font-bold">Sarah Miller</p>
          <p class="font-label-sm text-on-surface-variant">sarah.miller@flowtask.io</p>
        </div>
        <div class="py-1">
          <a href="settings.html" class="flex items-center gap-2 px-4 py-2 text-on-surface-variant hover:bg-surface-container hover:text-on-surface text-body-sm font-medium transition-colors">
            <span class="material-symbols-outlined text-[18px]">person</span>
            <span>My Profile</span>
          </a>
          <a href="settings.html" class="flex items-center gap-2 px-4 py-2 text-on-surface-variant hover:bg-surface-container hover:text-on-surface text-body-sm font-medium transition-colors">
            <span class="material-symbols-outlined text-[18px]">tune</span>
            <span>Preferences</span>
          </a>
          <a href="documents.html" class="flex items-center gap-2 px-4 py-2 text-on-surface-variant hover:bg-surface-container hover:text-on-surface text-body-sm font-medium transition-colors">
            <span class="material-symbols-outlined text-[18px]">help_outline</span>
            <span>Help & Docs</span>
          </a>
        </div>
        <div class="border-t border-surface-container pt-1">
          <a href="login.html" class="flex items-center gap-2 px-4 py-2 text-error hover:bg-error-container/30 text-body-sm font-semibold transition-colors">
            <span class="material-symbols-outlined text-[18px]">logout</span>
            <span>Log out</span>
          </a>
        </div>
      \`;
      profileContainer.appendChild(dropdown);

      profileContainer.addEventListener('click', (e) => {
        e.stopPropagation();
        dropdown.classList.toggle('hidden');
      });

      document.addEventListener('click', () => {
        dropdown.classList.add('hidden');
      });
    }
  }

  // Ctrl+K Shortcut to focus search
  const searchInput = document.querySelector('header input[type="text"]');
  window.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      if (searchInput) searchInput.focus();
    }
  });

  // Demo Toast helper
  const showToast = (message, icon = 'check_circle') => {
    let toast = document.getElementById('flowtask-toast');
    if (!toast) {
      toast = document.createElement('div');
      toast.id = 'flowtask-toast';
      toast.className = 'fixed bottom-6 right-6 z-50 flex items-center gap-3 px-4 py-3 bg-inverse-surface text-inverse-on-surface rounded-xl shadow-2xl transition-all transform duration-300 opacity-0 translate-y-4';
      document.body.appendChild(toast);
    }
    toast.innerHTML = \`
      <span class="material-symbols-outlined text-secondary-container text-title-sm">\${icon}</span>
      <span class="font-body-sm font-medium">\${message}</span>
    \`;
    toast.classList.remove('opacity-0', 'translate-y-4');
    setTimeout(() => {
      toast.classList.add('opacity-0', 'translate-y-4');
    }, 2800);
  };

  document.querySelectorAll('button').forEach(btn => {
    const text = btn.innerText.trim();
    if (text.includes('New Task') || text.includes('New Project') || text.includes('Quick Add')) {
      btn.addEventListener('click', () => showToast(\`Action triggered: \${text}\`, 'add_circle'));
    } else if (text.includes('Export')) {
      btn.addEventListener('click', () => showToast('Generating export bundle...', 'downloading'));
    }
  });
});
`;

fs.writeFileSync(path.join(targetDir, 'assets', 'js', 'main.js'), mainJsContent, 'utf8');
console.log('assets/js/main.js written.');

// 4. Menu Items Definition
const menuItems = [
  { path: 'dashboard', href: 'index.html', icon: 'dashboard', label: 'Dashboard' },
  { path: 'projects', href: 'projects.html', icon: 'folder_open', label: 'Projects' },
  { path: 'tasks', href: 'kanban.html', icon: 'check_box', label: 'Tasks' },
  { path: 'kanban', href: 'kanban.html', icon: 'view_kanban', label: 'Kanban' },
  { path: 'sprint-planning', href: 'sprint-planning.html', icon: 'flag', label: 'Sprint Planning' },
  { path: 'calendar', href: 'calendar.html', icon: 'calendar_today', label: 'Calendar' },
  { path: 'time-tracking', href: 'time-tracking.html', icon: 'schedule', label: 'Time Tracking' },
  { path: 'reports', href: 'forecast.html', icon: 'bar_chart', label: 'Reports & Forecast' },
  { path: 'analytics', href: 'analytics.html', icon: 'insights', label: 'Analytics' },
  { path: 'invoices', href: 'invoices.html', icon: 'receipt_long', label: 'Invoices & Billing' },
  { path: 'team', href: 'team.html', icon: 'groups', label: 'Team' },
  { path: 'clients', href: 'clients.html', icon: 'corporate_fare', label: 'Clients' },
  { path: 'client-portal', href: 'client-portal.html', icon: 'space_dashboard', label: 'Client Portal' },
  { path: 'documents', href: 'documents.html', icon: 'description', label: 'Documents' },
  { path: 'notifications', href: 'notifications.html', icon: 'notifications', label: 'Notifications' },
  { path: 'audit-log', href: 'audit-log.html', icon: 'history', label: 'Audit Log' },
  { path: 'settings', href: 'settings.html', icon: 'settings', label: 'Settings' }
];

// Helper to build standardized sidebar
function buildStandardizedAside(activePath) {
  const activeClass = 'flex items-center h-10 px-3.5 py-2 rounded-xl bg-primary-container text-on-primary font-semibold text-[14px] shadow-xs active';
  const inactiveClass = 'flex items-center h-10 px-3.5 py-2 rounded-xl text-on-surface-variant font-medium text-[14px] hover:bg-surface-container hover:text-on-surface transition-colors duration-150';

  const navLinks = menuItems.map(item => {
    const isActive = (item.path === activePath);
    const cls = isActive ? activeClass : inactiveClass;
    const current = isActive ? ' aria-current="page"' : '';
    return `<a class="${cls}" data-path="${item.path}" href="${item.href}"${current}><span class="material-symbols-outlined text-[20px] w-5 h-5 flex items-center justify-center flex-shrink-0 mr-3">${item.icon}</span><span class="nav-text truncate leading-none">${item.label}</span></a>`;
  }).join('\n      ');

  return `<aside class="fixed left-0 top-0 h-screen w-64 bg-surface-container-lowest shadow-[0_1px_8px_rgba(0,0,0,0.04)] z-50 flex flex-col justify-between overflow-y-auto min-h-screen">
  <div class="flex flex-col flex-1 min-h-0">
    <!-- Requirement 1: Standardized FLOWTASK Brand Logo -->
    <div class="p-space-md pb-2 flex-shrink-0">
      <a href="index.html" class="flex items-center gap-3 px-2 py-1.5 rounded-xl hover:bg-surface-container transition-all group cursor-pointer" title="FLOWTASK Dashboard">
        <div class="w-9 h-9 rounded-xl overflow-hidden shadow-sm shadow-primary/25 flex-shrink-0 group-hover:scale-105 transition-transform">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" fill="none" class="w-full h-full">
            <rect width="48" height="48" rx="12" fill="#2563EB"/>
            <path d="M14 16C14 14.8954 14.8954 14 16 14H32C33.1046 14 34 14.8954 34 16V20C34 21.1046 33.1046 22 32 22H16C14.8954 22 14 21.1046 14 20V16Z" fill="#FFFFFF"/>
            <path d="M14 26C14 24.8954 14.8954 24 16 24H26C27.1046 24 28 24.8954 28 26V30C28 31.1046 27.1046 32 26 32H16C14.8954 32 14 31.1046 14 30V26Z" fill="#6CF8BB"/>
            <circle cx="33" cy="28" r="4" fill="#B4C5FF"/>
          </svg>
        </div>
        <div class="flex items-center gap-1.5">
          <span class="font-headline-md text-[20px] font-extrabold tracking-tight text-on-surface">FlowTask</span>
          <span class="font-label-sm text-[11px] uppercase px-1.5 py-0.5 rounded-md bg-primary-fixed text-on-primary-fixed font-bold tracking-wider">PRO</span>
        </div>
      </a>
    </div>

    <!-- Workspace Context Switcher -->
    <div class="px-space-md mb-2 flex-shrink-0">
      <div class="flex items-center justify-between p-2 rounded-xl bg-surface-container-low hover:bg-surface-container transition-colors cursor-pointer">
        <div class="flex items-center gap-2.5 min-w-0">
          <div class="w-7 h-7 rounded-lg bg-primary text-white flex items-center justify-center font-bold text-[12px] flex-shrink-0 shadow-xs">FL</div>
          <div class="min-w-0 flex-1 text-left">
            <p class="font-title-sm text-[13px] font-bold text-on-surface truncate leading-tight">FlowTech Lab</p>
            <p class="font-label-sm text-[11px] text-on-surface-variant truncate leading-tight">14 members • Enterprise</p>
          </div>
        </div>
        <span class="material-symbols-outlined text-[18px] text-outline flex-shrink-0">unfold_more</span>
      </div>
    </div>

    <!-- Menu Label -->
    <div class="px-space-md pt-1 pb-1 flex-shrink-0">
      <span class="text-[10px] font-extrabold uppercase tracking-wider text-outline px-1">MAIN MENU</span>
    </div>

    <!-- Requirement 4: Standardized Navigation Menu -->
    <div class="flex-1 overflow-y-auto px-space-md py-1">
      <nav class="flex flex-col gap-0.5" data-active-classes="bg-primary-container text-on-primary font-semibold shadow-xs">
        ${navLinks}
      </nav>
    </div>
  </div>

  <!-- Requirement 3: Standardized Upgrade to Pro -->
  <div class="p-space-md border-t border-outline-variant/20 flex-shrink-0 mt-auto">
    <div class="bg-gradient-to-br from-primary to-primary-container p-3.5 rounded-2xl text-on-primary shadow-sm border border-primary/20">
      <div class="flex items-center gap-2 mb-1">
        <span class="material-symbols-outlined text-[18px] text-secondary-container">auto_awesome</span>
        <span class="font-title-sm text-[14px] font-bold text-white tracking-tight">Upgrade to Pro</span>
      </div>
      <p class="font-body-sm text-[12px] leading-relaxed text-on-primary-container/90 mb-3">
        Unlock enterprise features, workflows and team limits.
      </p>
      <a class="flex items-center justify-center gap-1.5 w-full py-2 px-3 bg-surface-container-lowest text-primary font-label-md text-[13px] font-bold rounded-xl shadow-xs hover:bg-surface-container-low transition-all" data-path="billing-plans" href="settings.html">
        <span>View Plans</span>
        <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
      </a>
    </div>
  </div>
</aside>`;
}

const pageActiveMap = {
  'index.html': 'dashboard',
  'projects.html': 'projects',
  'kanban.html': 'kanban',
  'sprint-planning.html': 'sprint-planning',
  'calendar.html': 'calendar',
  'time-tracking.html': 'time-tracking',
  'forecast.html': 'reports',
  'reports.html': 'reports',
  'analytics.html': 'analytics',
  'invoices.html': 'invoices',
  'team.html': 'team',
  'clients.html': 'clients',
  'client-portal.html': 'client-portal',
  'documents.html': 'documents',
  'notifications.html': 'notifications',
  'audit-log.html': 'audit-log',
  'settings.html': 'settings'
};

// Standardized Header across all 17 screens
function buildStandardizedHeader() {
  return `<header class="fixed top-0 left-64 right-0 h-16 bg-surface-container-lowest/85 backdrop-blur-xl border-b border-outline-variant/20 shadow-[0_1px_8px_rgba(0,0,0,0.03)] z-40 flex items-center justify-between px-space-xl">
  <!-- Global Search Bar (Ctrl+K) -->
  <div class="flex items-center gap-space-md flex-1 max-w-lg">
    <div class="relative w-full flex items-center">
      <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-outline text-[20px] pointer-events-none">search</span>
      <input class="w-full pl-10 pr-20 py-2 rounded-xl bg-surface-container-low font-body-sm text-body-sm text-on-surface placeholder:text-outline focus:outline-none focus:ring-2 focus:ring-primary-container transition-all" placeholder="Search projects, tasks, or people... (Ctrl+K)" type="text">
      <div class="absolute right-2.5 top-1/2 -translate-y-1/2 flex items-center gap-1 pointer-events-none">
        <kbd class="px-2 py-0.5 rounded bg-surface-container font-mono text-[11px] font-semibold text-outline border border-outline-variant/30 shadow-2xs">⌘ K</kbd>
      </div>
    </div>
  </div>

  <!-- Header Right Actions & Profile -->
  <div class="flex items-center gap-space-md">
    <!-- Quick Add Button -->
    <button class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-primary-container text-on-primary font-label-md text-label-md font-semibold hover:bg-primary transition-all shadow-xs active:scale-95 cursor-pointer" type="button" title="Quick Add">
      <span class="material-symbols-outlined text-[18px]">add</span>
      <span>Quick Add</span>
    </button>

    <!-- Notification Button with Pulse Badge -->
    <a href="notifications.html" class="relative p-2 rounded-full text-on-surface-variant hover:bg-surface-container hover:text-on-surface transition-colors cursor-pointer" title="Notifications">
      <span class="material-symbols-outlined text-[22px]">notifications</span>
      <span class="absolute top-1.5 right-1.5 w-2 h-2 bg-error rounded-full ring-2 ring-surface-container-lowest animate-pulse"></span>
    </a>

    <!-- Help & Documentation -->
    <a href="documents.html" class="p-2 rounded-full text-on-surface-variant hover:bg-surface-container hover:text-on-surface transition-colors cursor-pointer" title="Help & Documentation">
      <span class="material-symbols-outlined text-[22px]">help_outline</span>
    </a>

    <!-- User Profile Dropdown Trigger -->
    <div class="flex items-center gap-space-sm pl-space-xs border-l border-outline-variant/30 cursor-pointer group select-none" id="user-profile-trigger">
      <img alt="Sarah Miller" class="w-8 h-8 rounded-full object-cover ring-2 ring-primary-container/20 group-hover:ring-primary-container/40 transition-all flex-shrink-0" src="https://lh3.googleusercontent.com/aida-public/AB6AXuBckQHV06Vgo_oFLBwU0NVVlVj_0WT6m3kovkvHYvLXeJDHSAbjz_ic-BVlm809docSBQu7aOl7vlGuVNJprduqHxlMutvrVC2EN0FdG_IZE5mGnpWA1zjWRCSOaLN_1UsjuWHJ8m19nAJXMyXhJ04WX6BMw7ftynqkzezR6QVgXxPcKXLHIk5stEHtNEdV-8Yd1tsttFTGMzhCF9UESmsGn6lY91mbxiJIUFNlOW3P4YSkFDx5dtV6Sg">
      <div class="hidden md:flex flex-col text-left">
        <span class="font-label-md text-label-md text-on-surface font-bold leading-tight group-hover:text-primary transition-colors">Sarah Miller</span>
        <span class="font-label-sm text-label-sm text-on-surface-variant leading-tight">Product Manager</span>
      </div>
      <span class="material-symbols-outlined text-[20px] text-outline group-hover:text-on-surface transition-colors flex-shrink-0">expand_more</span>
    </div>
  </div>
</header>`;
}

// 5. Process all 17 App Pages
for (const [filename, activePath] of Object.entries(pageActiveMap)) {
  const filePath = path.join(targetDir, filename);
  if (!fs.existsSync(filePath)) continue;

  let content = fs.readFileSync(filePath, 'utf8');

  // Replace <aside>...</aside>
  const newAside = buildStandardizedAside(activePath);
  content = content.replace(/<aside[^>]*>[\s\S]*?<\/aside>/, newAside);

  // Standardize shell container div
  content = content.replace(/<\/aside>\s*<div class="pl-64[^"]*">/, '</aside>\n<div class="pl-64 min-h-screen flex flex-col">');

  // Standardize full header
  const standardizedHeader = buildStandardizedHeader();
  content = content.replace(/<header[^>]*>[\s\S]*?<\/header>/, standardizedHeader);

  // Ensure <main> has relative pt-16 bg-background min-h-screen
  content = content.replace(/<main[^>]*>/, '<main class="relative pt-16 bg-background min-h-screen">');

  // Normalize Content Container Width inside <main>
  content = content.replace(/(<main class="relative pt-16 bg-background min-h-screen">)([\s\S]*?)(<\/main>)/, (match, openMain, mainInner, closeMain) => {
    let inner = mainInner.trim();

    // If inner starts with an outer div, replace its class with content-fixed-container
    const firstDivMatch = inner.match(/^(\s*<div\s+class=")([^"]*)(")/i);
    if (firstDivMatch) {
      // Replace first outer div with content-fixed-container
      const restInner = inner.slice(firstDivMatch[0].length);
      inner = `\n  <div class="content-fixed-container w-full max-w-[1600px] mx-auto p-space-lg lg:p-space-xl flex flex-col gap-space-lg"${restInner}`;
    } else {
      inner = `\n  <div class="content-fixed-container w-full max-w-[1600px] mx-auto p-space-lg lg:p-space-xl flex flex-col gap-space-lg">\n    ${inner}\n  </div>`;
    }

    // Strip nested page-level max-w-7xl, max-w-[1720px], max-w-[1600px] on child containers
    inner = inner.replace(/p-space-lg\s+max-w-7xl\s+mx-auto\s+w-full\s+space-y-space-lg/g, 'w-full space-y-space-lg');
    inner = inner.replace(/w-full\s+max-w-7xl\s+mx-auto\s+px-space-lg\s+lg:px-space-xl\s+py-space-lg\s+space-y-space-lg/g, 'w-full space-y-space-lg');
    inner = inner.replace(/px-space-xl\s+py-space-lg\s+space-y-space-xl\s+max-w-7xl\s+mx-auto\s+w-full/g, 'w-full space-y-space-lg');
    inner = inner.replace(/relative\s+px-space-xl\s+py-space-lg\s+max-w-7xl\s+mx-auto\s+w-full/g, 'relative w-full flex flex-col gap-space-lg');
    inner = inner.replace(/px-space-xl\s+py-space-lg\s+flex\s+flex-col\s+gap-space-lg/g, 'w-full flex flex-col gap-space-lg');
    inner = inner.replace(/px-space-xl\s+pt-space-lg\s+pb-space-lg/g, 'w-full pb-space-xs');
    inner = inner.replace(/px-space-xl\s+pb-space-xl/g, 'w-full pb-space-xl');
    inner = inner.replace(/max-w-7xl\s+mx-auto/g, 'w-full');
    inner = inner.replace(/max-w-\[1720px\]\s+mx-auto/g, 'w-full');

    return `${openMain}${inner}\n${closeMain}`;
  });

  fs.writeFileSync(filePath, content, 'utf8');
  console.log(`Standardized ${filename}: logo, menu, upgrade-to-pro, and container 1600px`);
}

// 6. Update login.html Logo
const loginFile = path.join(targetDir, 'login.html');
if (fs.existsSync(loginFile)) {
  let loginContent = fs.readFileSync(loginFile, 'utf8');
  loginContent = loginContent.replace(
    /<a href="index\.html" class="inline-flex items-center gap-3[\s\S]*?<\/a>/,
    `<a href="index.html" class="inline-flex items-center gap-3 group cursor-pointer" title="FlowTask">
          <div class="w-10 h-10 rounded-xl overflow-hidden shadow-sm flex-shrink-0 group-hover:scale-105 transition-transform">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" fill="none" class="w-full h-full">
              <rect width="48" height="48" rx="12" fill="#2563EB"/>
              <path d="M14 16C14 14.8954 14.8954 14 16 14H32C33.1046 14 34 14.8954 34 16V20C34 21.1046 33.1046 22 32 22H16C14.8954 22 14 21.1046 14 20V16Z" fill="#FFFFFF"/>
              <path d="M14 26C14 24.8954 14.8954 24 16 24H26C27.1046 24 28 24.8954 28 26V30C28 31.1046 27.1046 32 26 32H16C14.8954 32 14 31.1046 14 30V26Z" fill="#6CF8BB"/>
              <circle cx="33" cy="28" r="4" fill="#B4C5FF"/>
            </svg>
          </div>
          <div class="flex items-center gap-1.5">
            <span class="font-headline-md text-[22px] font-extrabold tracking-tight text-white">FlowTask</span>
            <span class="font-label-sm text-[11px] uppercase px-1.5 py-0.5 rounded-md bg-white/20 text-white font-bold tracking-wider">PRO</span>
          </div>
        </a>`
  );
  fs.writeFileSync(loginFile, loginContent, 'utf8');
  console.log('login.html logo standardized.');
}

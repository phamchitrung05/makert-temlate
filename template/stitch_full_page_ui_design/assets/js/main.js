/**
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
      dropdown.innerHTML = `
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
      `;
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
    toast.innerHTML = `
      <span class="material-symbols-outlined text-secondary-container text-title-sm">${icon}</span>
      <span class="font-body-sm font-medium">${message}</span>
    `;
    toast.classList.remove('opacity-0', 'translate-y-4');
    setTimeout(() => {
      toast.classList.add('opacity-0', 'translate-y-4');
    }, 2800);
  };

  document.querySelectorAll('button').forEach(btn => {
    const text = btn.innerText.trim();
    if (text.includes('New Task') || text.includes('New Project') || text.includes('Quick Add')) {
      btn.addEventListener('click', () => showToast(`Action triggered: ${text}`, 'add_circle'));
    } else if (text.includes('Export')) {
      btn.addEventListener('click', () => showToast('Generating export bundle...', 'downloading'));
    }
  });
});

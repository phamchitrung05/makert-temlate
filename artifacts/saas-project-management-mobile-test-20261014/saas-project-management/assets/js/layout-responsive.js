/**
 * =====================================================================
 * CHỨC NĂNG FILE: Thu gọn sidebar thành menu có thể mở trên màn hình nhỏ.
 * CÁC HÀM/METHOD TRONG FILE:
 * - initResponsiveNavigation(): khởi tạo một lần khi DOM sẵn sàng.
 * - setOpen(open, restoreFocus): cập nhật menu và trạng thái accessibility.
 * - syncViewport(): đặt lại trạng thái khi đi qua breakpoint.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : Marker main-sidebar, app-shell và matchMedia 900px.
 * - OUTPUT: Nút menu, hidden/aria-expanded; không tạo overlay hoặc khóa cuộn.
 * =====================================================================
 */
(() => {
  /** Input: DOM hiện tại. Output: gắn nút và listener; bỏ qua nếu đã khởi tạo. */
  function initResponsiveNavigation() {
    const body = document.querySelector('body[data-layout-contract="v1"]');
    const sidebar = body?.querySelector('[data-purpose="main-sidebar"]');
    if (!sidebar || body.dataset.responsiveNavReady) return;
    // Paired exports use the authored mobile document below the breakpoint.
    // Do not add a second disclosure control to the hidden desktop shell.
    if (body.querySelector('[data-layout-variant="mobile-frame"]')) {
      body.dataset.responsiveNavReady = 'true';
      return;
    }
    // An existing mobile navigation must be reviewed by the agent, not doubled.
    if (body.querySelector('[data-layout-toggle]')) return;

    const small = window.matchMedia('(max-width: 900px)');
    const button = document.createElement('button');
    button.type = 'button';
    button.dataset.layoutToggle = '';
    button.textContent = body.dataset.menuLabel || 'Menu';
    if (!sidebar.id) {
      let id = 'stitch-main-sidebar';
      while (document.getElementById(id)) id += '-nav';
      sidebar.id = id;
    }
    button.setAttribute('aria-controls', sidebar.id);
    const shell = body.querySelector('[data-purpose="app-shell"]');
    if (shell) {
      // Keep the disclosure outside any source flex row. Some exports nest
      // aside/main in an inner wrapper; placing the button beside that wrapper
      // prevents it from consuming the content column at mobile widths.
      shell.insertBefore(button, shell.firstChild);
    } else {
      sidebar.before(button);
    }
    body.dataset.responsiveNavReady = 'true';

    /** Input: trạng thái mở, tùy chọn trả focus. Output: đồng bộ nút và sidebar. */
    function setOpen(open, restoreFocus = false) {
      sidebar.hidden = small.matches && !open;
      button.setAttribute('aria-expanded', String(!sidebar.hidden));
      if (restoreFocus) button.focus();
    }

    /** Input: breakpoint hiện tại. Output: reset menu; tránh để focus trong vùng ẩn. */
    function syncViewport() {
      const focusWasInSidebar = sidebar.contains(document.activeElement);
      const focusWasOnToggle = document.activeElement === button;
      button.hidden = !small.matches;
      setOpen(!small.matches, small.matches && focusWasInSidebar);
      if (!small.matches && focusWasOnToggle) {
        sidebar.querySelector('a[href], button')?.focus();
      }
    }

    button.addEventListener('click', () => setOpen(sidebar.hidden));
    body.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && small.matches && !sidebar.hidden) {
        setOpen(false, true);
      }
    });
    sidebar.addEventListener('click', (event) => {
      if (small.matches && event.target.closest('a[href]')) setOpen(false, true);
    });
    small.addEventListener('change', syncViewport);
    syncViewport();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initResponsiveNavigation, { once: true });
  } else {
    initResponsiveNavigation();
  }
})();

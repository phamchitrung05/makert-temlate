/**
 * =====================================================================
 * CHỨC NĂNG FILE: Khai báo menu điều hướng ngang cho admin Vue
 * =====================================================================
 *
 * Menu ngang dùng cùng route name với vertical navigation. Resource nằm
 * trong Ecommerce; Media là nhóm nghiệp vụ riêng cho file dùng chung.
 * Systerm AI tập trung Ai Content, Ai Prompt và AI Settings, cùng route với menu dọc.
 * Ai Prompt có List đọc mẫu đã lưu và Add phân tích bài tham khảo để tạo mẫu.
 * SYSTERM SETTING chứa trang SETTING trống.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - Không có; file export một mảng cấu hình menu.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : route name, icon và cấu hình children.
 * - OUTPUT: mảng navigation dùng cho horizontal layout.
 * =====================================================================
 */
export default [
  {
    title: 'SYSTERM SETTING',
    icon: { icon: 'tabler-settings' },
    children: [{ title: 'SETTING', to: 'settings' }],
  },
  {
    title: 'Systerm AI',
    icon: { icon: 'tabler-sparkles' },
    children: [
      { title: 'Ai Content', to: 'ai-content' },
      {
        title: 'Ai Prompt',
        icon: { icon: 'tabler-file-text' },
        action: 'manage',
        subject: 'ai_settings',
        children: [
          { title: 'List', to: 'ai-prompt-list', action: 'manage', subject: 'ai_settings' },
          { title: 'Add', to: 'ai-prompt-add', action: 'manage', subject: 'ai_settings' },
        ],
      },
      {
        title: 'AI Settings',
        icon: { icon: 'tabler-brain' },
        to: 'settings-ai-providers',
        action: 'manage',
        subject: 'ai_settings',
      },
    ],
  },
  {
    title: 'Apps',
    icon: { icon: 'tabler-layout-grid-add' },
    children: [
      {
        title: 'Ecommerce',
        icon: { icon: 'tabler-shopping-cart-plus' },
        children: [
          {
            title: 'Dashboard',
            to: 'apps-ecommerce-dashboard',
          },
          {
            title: 'Product',
            children: [
              { title: 'List', to: 'apps-ecommerce-product-list' },
              { title: 'Add', to: 'apps-ecommerce-product-add' },
              { title: 'Category', to: 'apps-ecommerce-product-category-list' },
            ],
          },
          {
            title: 'Resource',
            children: [
              { title: 'List', to: 'apps-ecommerce-resource-list' },
              { title: 'Add', to: 'apps-ecommerce-resource-add' },
              { title: 'Versions', to: 'apps-ecommerce-resource-version-list' },
            ],
          },
          {
            title: 'Order',
            children: [
              { title: 'List', to: 'apps-ecommerce-order-list' },
              { title: 'Details', to: { name: 'apps-ecommerce-order-details-id', params: { id: '9042' } } },
            ],
          },
          {
            title: 'Customer',
            children: [
              { title: 'List', to: 'apps-ecommerce-customer-list' },
              { title: 'Details', to: { name: 'apps-ecommerce-customer-details-id', params: { id: 478426 } } },
            ],
          },
          {
            title: 'Manage Review',
            to: 'apps-ecommerce-manage-review',
          },
          {
            title: 'Referrals',
            to: 'apps-ecommerce-referrals',
          },
          {
            title: 'Settings',
            to: 'apps-ecommerce-settings',
          },
        ],
      },
      {
        title: 'Blog',
        icon: { icon: 'tabler-news' },
        children: [
          { title: 'Posts', to: 'apps-blog-post-list' },
          { title: 'Add Post', to: 'apps-blog-post-add' },
        ],
      },
      {
        title: 'Media',
        icon: { icon: 'tabler-photo' },
        children: [
          { title: 'File', to: 'apps-media-file' },
          { title: 'Media Asset', to: 'apps-media-media-asset' },
        ],
      },
      {
        title: 'Academy',
        icon: { icon: 'tabler-book' },
        children: [
          { title: 'Dashboard', to: 'apps-academy-dashboard' },
          { title: 'My Course', to: 'apps-academy-my-course' },
          { title: 'Course Details', to: 'apps-academy-course-details' },
        ],
      },
      {
        title: 'Logistics',
        icon: { icon: 'tabler-truck' },
        children: [
          { title: 'Dashboard', to: 'apps-logistics-dashboard' },
          { title: 'Fleet', to: 'apps-logistics-fleet' },
        ],
      },
      {
        title: 'Email',
        icon: { icon: 'tabler-mail' },
        to: 'apps-email',
      },
      {
        title: 'Chat',
        icon: { icon: 'tabler-message-circle' },
        to: 'apps-chat',
      },
      {
        title: 'Calendar',
        to: 'apps-calendar',
        icon: { icon: 'tabler-calendar' },
      },
      {
        title: 'Kanban',
        icon: { icon: 'tabler-layout-kanban' },
        to: 'apps-kanban',
      },
      {
        title: 'Invoice',
        icon: { icon: 'tabler-file-dollar' },
        children: [
          { title: 'List', to: 'apps-invoice-list' },
          { title: 'Preview', to: { name: 'apps-invoice-preview-id', params: { id: '5036' } } },
          { title: 'Edit', to: { name: 'apps-invoice-edit-id', params: { id: '5036' } } },
          { title: 'Add', to: 'apps-invoice-add' },
        ],
      },
      {
        title: 'User',
        icon: { icon: 'tabler-users' },
        children: [
          { title: 'List', to: 'apps-user-list' },
          { title: 'View', to: { name: 'apps-user-view-id', params: { id: 21 } } },
        ],
      },
      {
        title: 'Roles & Permissions',
        icon: { icon: 'tabler-settings' },
        to: 'apps-roles',
      },
    ],
  },
]

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Khai báo menu điều hướng ngang cho admin Vue
 * =====================================================================
 *
 * Menu ngang dùng cùng route name với vertical navigation. Resource nằm
 * trong Ecommerce; Media là nhóm nghiệp vụ riêng cho file dùng chung.
 * Blog, Taxonomy và SYSTERM được tách thành các nhóm menu nghiệp vụ riêng.
 * Systerm AI tập trung Ai Content, Ai Prompt và AI Settings, cùng route với menu dọc.
 * AI Approved hiển thị snapshot các bài AI đã duyệt lưu dài hạn.
 * Ai Prompt có List đọc mẫu đã lưu và Add phân tích bài tham khảo để tạo mẫu.
 * Các mục Page/Category/Tag hiện là placeholder vì giao diện sẽ bổ sung sau.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - Không có; file export một mảng cấu hình menu.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : route name, icon và cấu hình children.
 * - OUTPUT: mảng navigation dùng cho horizontal layout.
 * =====================================================================
 */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cấu hình menu ứng dụng cho layout ngang.
 * CÁC HÀM/METHOD TRONG FILE: không có; mảng cấu hình tĩnh.
 * INPUT/OUTPUT CỦA FILE (tổng thể): route name -> menu; chi tiết mở từ bản ghi ở list.
 * =====================================================================
 */
export default [
  {
    title: 'Systerm AI',
    icon: { icon: 'tabler-sparkles' },
    children: [
      { title: 'Ai Content', to: 'ai-content' },
      { title: 'AI Approved', to: 'ai-approved', action: 'manage', subject: 'posts' },
      {
        title: 'Ai Prompt',
        icon: { icon: 'tabler-file-text' },
        action: 'manage',
        subject: 'ai_settings',
        children: [
          { title: 'List', to: 'ai-prompt-list', action: 'manage', subject: 'ai_settings' },
          { title: 'Add', to: 'ai-prompt-add', action: 'manage', subject: 'ai_settings' },
          { title: 'Prompt tạo ảnh', to: 'ai-image-prompt', action: 'manage', subject: 'ai_settings' },
        ],
      },
      {
        title: 'Visual Styles',
        icon: { icon: 'tabler-palette' },
        action: 'manage',
        subject: 'ai_settings',
        children: [
          { title: 'Danh sách', to: 'visual-styles', action: 'manage', subject: 'ai_settings' },
          { title: 'Tạo ảnh', to: 'visual-styles-generate', action: 'manage', subject: 'ai_settings' },
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
    title: 'MARKETING',
    icon: { icon: 'tabler-speakerphone' },
    children: [
      {
        title: 'Social Post',
        action: 'view',
        subject: 'marketing',
        children: [
          { title: 'List', to: 'marketing-social-post-list' },
        ],
      },
    ],
  },
  {
    title: 'Blog',
    icon: { icon: 'tabler-news' },
    children: [
      {
        title: 'Post',
        children: [
          { title: 'List', to: 'apps-blog-post-list' },
          { title: 'Add', to: 'apps-blog-post-add' },
        ],
      },
      {
        title: 'Page',
        children: [
          { title: 'List', to: null },
          { title: 'Add', to: null },
        ],
      },
    ],
  },
  {
    title: 'Taxonomy',
    icon: { icon: 'tabler-category' },
    children: [
      { title: 'Category', to: 'apps-taxonomy-category' },
      { title: 'Tag', to: null },
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
    title: 'SYSTERM',
    icon: { icon: 'tabler-settings' },
    children: [
      { title: 'Seting', to: 'settings' },
      {
        title: 'User',
        children: [
          { title: 'List', to: 'apps-user-list' },
        ],
      },
      { title: 'Role & Permissions', to: 'apps-roles' },
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
            ],
          },
          {
            title: 'Customer',
            children: [
              { title: 'List', to: 'apps-ecommerce-customer-list' },
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
          { title: 'Add', to: 'apps-invoice-add' },
        ],
      },
    ],
  },
]

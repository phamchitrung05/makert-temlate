/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chuẩn hóa DTO access API thành model hiển thị Roles.
 * =====================================================================
 * API trả tên role/permission dạng kỹ thuật. File này bổ sung nhãn, icon,
 * mô tả và cấu trúc module theo theme mà không thay đổi dữ liệu server.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - mapRole(): chuyển RoleResource thành model cho list/detail.
 * - mapPermissionCatalog(): chuyển catalog groups thành module ma trận.
 * - mapPermissionGroups(): gom module thành bảy nhóm hiển thị trong dialog.
 * - getRolePermissionIds(): lấy permission IDs từ role DTO.
 * - getRoleLabel(): tạo nhãn dễ đọc cho role tùy chỉnh.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : RoleResource và catalog thật từ API Laravel.
 * - OUTPUT: model hiển thị có permission IDs, metadata theme và version.
 * - SIDE EFFECT: Không gọi API, không ghi database hoặc storage.
 * =====================================================================
 */

const roleVisuals = {
  'super-admin': {
    label: 'Super Admin',
    description: 'Toàn quyền quản trị và bảo vệ cấu hình truy cập hệ thống.',
    icon: 'tabler-crown',
    color: 'primary',
  },
  admin: {
    label: 'Admin',
    description: 'Quản lý người dùng, nội dung và cấu hình trong phạm vi được cấp.',
    icon: 'tabler-shield-check',
    color: 'info',
  },
  editor: {
    label: 'Editor',
    description: 'Tạo, chỉnh sửa và quản lý nội dung theo permission được cấp.',
    icon: 'tabler-pencil',
    color: 'success',
  },
  support: {
    label: 'Support',
    description: 'Hỗ trợ vận hành và tra cứu dữ liệu trong phạm vi được cấp.',
    icon: 'tabler-headset',
    color: 'warning',
  },
}

const groupVisuals = {
  resources: { title: 'Tài nguyên', description: 'Quản lý tài nguyên và vòng đời xuất bản.', icon: 'tabler-box' },
  'resource_versions': { title: 'Phiên bản tài nguyên', description: 'Quản lý các phiên bản của tài nguyên.', icon: 'tabler-git-branch' },
  posts: { title: 'Bài viết', description: 'Tạo và quản lý nội dung bài viết.', icon: 'tabler-file-text' },
  taxonomy: { title: 'Phân loại', description: 'Quản lý danh mục, tag và công nghệ.', icon: 'tabler-category' },
  media: { title: 'Media', description: 'Quản lý thư viện và tài nguyên media.', icon: 'tabler-photo' },
  users: { title: 'Người dùng', description: 'Quản lý tài khoản và vai trò quản trị.', icon: 'tabler-users' },
  roles: { title: 'Vai trò', description: 'Tạo, chỉnh sửa và xóa vai trò quản trị.', icon: 'tabler-shield-cog' },
  analytics: { title: 'Phân tích', description: 'Xem báo cáo và số liệu phân tích.', icon: 'tabler-chart-line' },
  settings: { title: 'Cài đặt', description: 'Quản lý cấu hình hệ thống.', icon: 'tabler-settings' },
  'ai_settings': { title: 'AI Settings', description: 'Quản lý provider, model và cấu hình AI.', icon: 'tabler-sparkles' },
  categories: { title: 'Danh mục', description: 'Quản lý cây danh mục của resource.', icon: 'tabler-list-tree' },
  tags: { title: 'Tag', description: 'Quản lý tag dùng để phân loại nội dung.', icon: 'tabler-tags' },
  technologies: { title: 'Công nghệ', description: 'Quản lý công nghệ gắn với resource.', icon: 'tabler-code' },
  customers: { title: 'Khách hàng', description: 'Quản lý tài khoản khách hàng của hệ thống.', icon: 'tabler-user-circle' },
  'customer_identities': { title: 'Định danh khách hàng', description: 'Quản lý identity liên kết với khách hàng.', icon: 'tabler-id' },
  'media_assets': { title: 'Media Asset', description: 'Quản lý metadata và vòng đời file media.', icon: 'tabler-photo' },
  'media_asset_usages': { title: 'Liên kết Media', description: 'Quản lý liên kết media với model nghiệp vụ.', icon: 'tabler-link' },
  'seo_metadata': { title: 'SEO Metadata', description: 'Quản lý metadata SEO của nội dung.', icon: 'tabler-seo' },
  'ai_imports': { title: 'AI Import', description: 'Quản lý phiên nhập và pipeline nội dung AI.', icon: 'tabler-robot' },
  'ai_import_steps': { title: 'Bước AI Import', description: 'Theo dõi các bước xử lý của phiên AI import.', icon: 'tabler-list-check' },
  'ai_providers': { title: 'AI Provider', description: 'Quản lý kết nối và trạng thái provider AI.', icon: 'tabler-cloud-cog' },
  'ai_models': { title: 'AI Model', description: 'Quản lý model và capability của provider AI.', icon: 'tabler-brain' },
  'ai_writing_profiles': { title: 'Văn phong AI', description: 'Quản lý profile văn phong đã lưu.', icon: 'tabler-writing' },
  'ai_writing_profile_analyses': { title: 'Phân tích văn phong', description: 'Quản lý kết quả phân tích văn phong AI.', icon: 'tabler-file-search' },
  'ai_article_archives': { title: 'Archive bài AI', description: 'Quản lý snapshot archive của nội dung AI.', icon: 'tabler-archive' },
  'ai_provenance': { title: 'Nguồn AI', description: 'Quản lý provenance và nguồn tham chiếu AI.', icon: 'tabler-source-code' },
}

const permissionGroupVisuals = [
  {
    id: 'content',
    title: 'Nội dung & SEO',
    description: 'Quản lý tài nguyên, bài viết, phiên bản và metadata SEO.',
    icon: 'tabler-file-description',
    modules: ['resources', 'resource_versions', 'posts', 'seo_metadata'],
  },
  {
    id: 'taxonomy',
    title: 'Phân loại',
    description: 'Tổ chức danh mục, tag và công nghệ cho nội dung.',
    icon: 'tabler-category-2',
    modules: ['taxonomy', 'categories', 'tags', 'technologies'],
  },
  {
    id: 'media',
    title: 'Media',
    description: 'Quản lý file media, metadata và các liên kết sử dụng.',
    icon: 'tabler-photo',
    modules: ['media', 'media_assets', 'media_asset_usages'],
  },
  {
    id: 'ai-content',
    title: 'Nội dung AI',
    description: 'Quản lý import, văn phong, phân tích và nguồn nội dung AI.',
    icon: 'tabler-robot',
    modules: [
      'ai_imports',
      'ai_import_steps',
      'ai_writing_profiles',
      'ai_writing_profile_analyses',
      'ai_article_archives',
      'ai_provenance',
    ],
  },
  {
    id: 'ai-config',
    title: 'Cấu hình AI',
    description: 'Quản lý provider, model và các thiết lập AI.',
    icon: 'tabler-sparkles',
    modules: ['ai_settings', 'ai_providers', 'ai_models'],
  },
  {
    id: 'accounts',
    title: 'Tài khoản',
    description: 'Quản lý người dùng, khách hàng và định danh liên kết.',
    icon: 'tabler-users',
    modules: ['users', 'roles', 'customers', 'customer_identities'],
  },
  {
    id: 'system',
    title: 'Hệ thống & Báo cáo',
    description: 'Quản lý cài đặt hệ thống và theo dõi số liệu phân tích.',
    icon: 'tabler-bell-cog',
    modules: ['settings', 'analytics'],
  },
]

const actionLabels = {
  view: 'Xem',
  create: 'Tạo mới',
  update: 'Chỉnh sửa',
  delete: 'Xóa',
  publish: 'Xuất bản',
  archive: 'Lưu trữ',
  manage: 'Quản lý',
  upload: 'Tải lên',
  attach: 'Gắn vào',
  retry: 'Thử lại',
  cancel: 'Hủy',
  regenerate: 'Tạo lại',
  apply: 'Áp dụng',
  restore: 'Khôi phục',
  disable: 'Tắt',
  test: 'Kiểm tra',
  sync: 'Đồng bộ',
}

/**
 * Tạo nhãn hiển thị từ tên role kỹ thuật khi role không có metadata dựng sẵn.
 *
 * Input: roleName dạng slug lower-case.
 * Output: chuỗi tên viết hoa từ đầu, giữ nguyên dấu phân cách dễ đọc.
 */
const getRoleLabel = roleName => roleName
  .split(/[-_]+/)
  .filter(Boolean)
  .map(part => part.charAt(0).toUpperCase() + part.slice(1))
  .join(' ')

/**
 * Chuyển RoleResource thành model dùng chung cho các panel Roles.
 *
 * Input: object role gồm id/name/permissions/users_count/version và các cờ API.
 * Output: model có metadata theme, nhãn tiếng Việt và permissionIds dạng số.
 */
const mapRole = role => {
  const visual = roleVisuals[role.name] ?? {
    label: getRoleLabel(role.name),
    description: 'Vai trò tùy chỉnh của hệ thống.',
    icon: 'tabler-shield',
    color: 'secondary',
  }

  const permissionIds = (role.permissions ?? []).map(permission => Number(permission.id))

  const isSystem = Boolean(role.is_system)

  return {
    ...role,
    label: visual.label,
    desc: `${permissionIds.length} quyền · ${Number(role.users_count ?? 0)} người dùng`,
    fullDesc: visual.description,
    userCount: Number(role.users_count ?? 0),
    status: isSystem ? 'Hệ thống' : 'Tùy chỉnh',
    active: true,
    icon: visual.icon,
    color: visual.color,
    isSystem,
    canEdit: Boolean(role.can_edit),
    canDelete: Boolean(role.can_delete),
    permissionIds,
  }
}

/**
 * Chuyển catalog permission từ backend thành các nhóm checkbox.
 *
 * Input: payload catalog có groups[].permissions[].
 * Output: module có title/desc/icon và action id là permission ID thật.
 */
const mapPermissionCatalog = catalog => (catalog?.groups ?? []).map(group => {
  const visual = groupVisuals[group.name] ?? {
    title: getRoleLabel(group.name),
    description: `Quyền thuộc nhóm ${getRoleLabel(group.name)}.`,
    icon: 'tabler-lock',
  }

  return {
    id: group.name,
    title: visual.title,
    desc: visual.description,
    icon: visual.icon,
    actions: (group.permissions ?? []).map(permission => ({
      id: Number(permission.id),
      name: permission.name,
      label: actionLabels[permission.action] ?? getRoleLabel(permission.action),
      assignable: Boolean(permission.assignable),
    })),
  }
})

/**
 * Gom các module catalog vào bảy nhóm hiển thị thống nhất trong dialog.
 *
 * Input: mảng module đã được map bởi mapPermissionCatalog().
 * Output: bảy nhóm có metadata giao diện và toàn bộ module/action tương ứng.
 * Side effect: Không mutate mảng module đầu vào hoặc gọi API.
 */
const mapPermissionGroups = modules => {
  const modulesById = new Map(modules.map(module => [module.id, module]))
  const configuredModuleIds = new Set(permissionGroupVisuals.flatMap(group => group.modules))
  const unassignedModules = modules.filter(module => !configuredModuleIds.has(module.id))

  return permissionGroupVisuals.map(group => ({
    id: group.id,
    title: group.title,
    desc: group.description,
    icon: group.icon,
    modules: [
      ...group.modules.map(moduleId => modulesById.get(moduleId)).filter(Boolean),
      ...(group.id === 'system' ? unassignedModules : []),
    ],
  }))
}

/**
 * Lấy danh sách ID permission đang gán cho role.
 *
 * Input: role DTO từ API.
 * Output: mảng số nguyên distinct, dùng làm draft checkbox.
 */
const getRolePermissionIds = role => [...new Set((role?.permissions ?? []).map(permission => Number(permission.id)))]

export {
  getRoleLabel,
  getRolePermissionIds,
  mapPermissionCatalog,
  mapPermissionGroups,
  mapRole,
}

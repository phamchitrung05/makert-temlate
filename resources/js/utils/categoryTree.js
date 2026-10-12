/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chuyển cây Category thành options có nhãn phân cấp.
 * =====================================================================
 *
 * Utility giữ logic trình bày cây Category tách khỏi component quản lý CRUD.
 * Mỗi cấp con được thêm số dấu `-` tương ứng để VSelect vẫn giữ trải nghiệm
 * chọn dạng danh sách nhưng thể hiện rõ quan hệ cha – con.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - flattenCategoryOptions(): duyệt cây theo thứ tự cha trước con.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : cây Category đã có children và tập ID cần loại khỏi options.
 * - OUTPUT: danh sách `{ title, value, depth }` dùng cho VSelect.
 * - SIDE EFFECT: không có; hàm thuần, không mutate dữ liệu đầu vào.
 * =====================================================================
 */

/**
 * Chuyển cây Category thành danh sách option có tiền tố theo cấp.
 *
 * Input: nodes là cây Category; blockedIds là các ID không được chọn làm cha.
 * Output: options theo thứ tự pre-order với title dạng `- Tên` hoặc `-- Tên`.
 */
const flattenCategoryOptions = (nodes, blockedIds = new Set(), depth = 0) => {
  const options = []

  nodes.forEach(node => {
    if (blockedIds.has(node.id))
      return

    options.push({
      title: `${'-'.repeat(depth)}${depth > 0 ? ' ' : ''}${node.name}`,
      value: node.id,
      depth,
    })

    options.push(...flattenCategoryOptions(node.children ?? [], blockedIds, depth + 1))
  })

  return options
}

export { flattenCategoryOptions }

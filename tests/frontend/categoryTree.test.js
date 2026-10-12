/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử options Category dạng cây cho VSelect.
 * =====================================================================
 *
 * Test bảo đảm Category cha đứng trước Category con và nhãn hiển thị số dấu
 * `-` đúng theo cấp, đồng thời node bị chặn không xuất hiện trong danh sách.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - flattenCategoryOptions(): kiểm tra thứ tự, nhãn và giá trị option.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : cây Category giả lập và tập ID cần loại.
 * - OUTPUT: assertion trên options cho field Danh mục cha.
 * =====================================================================
 */
import { describe, expect, it } from 'vitest'
import { flattenCategoryOptions } from '@/utils/categoryTree'

describe('Category tree options', () => {
  it('flattens categories with a dash for each child level', () => {
    const tree = [{
      id: 1,
      name: 'Động vật',
      children: [{
        id: 2,
        name: 'Sư tử',
        children: [{ id: 3, name: 'Sư tử con', children: [] }],
      }],
    }]

    expect(flattenCategoryOptions(tree)).toEqual([
      { title: 'Động vật', value: 1, depth: 0 },
      { title: '- Sư tử', value: 2, depth: 1 },
      { title: '-- Sư tử con', value: 3, depth: 2 },
    ])
  })

  it('skips blocked categories and their descendants', () => {
    const tree = [{
      id: 1,
      name: 'Động vật',
      children: [{ id: 2, name: 'Sư tử', children: [{ id: 3, name: 'Sư tử con' }] }],
    }]

    expect(flattenCategoryOptions(tree, new Set([2]))).toEqual([
      { title: 'Động vật', value: 1, depth: 0 },
    ])
  })
})

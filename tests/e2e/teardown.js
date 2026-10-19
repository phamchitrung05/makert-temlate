/**
 * =====================================================================
 * CHỨC NĂNG FILE: Dọn DB/storage tạm sau E2E, có kiểm tra đường dẫn trước khi xóa.
 * CÁC HÀM/METHOD TRONG FILE: teardown().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): thư mục E2E do config tạo -> dọn fixture sau khi server dừng.
 * =====================================================================
 */
import { existsSync, realpathSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { basename, dirname } from 'node:path'

/** Input: thư mục tạm của lượt chạy. Output: xóa riêng fixture E2E; từ chối đường dẫn khác. */
export default function teardown() {
  const root = process.env.MAKERT_E2E_ROOT
  if (!root || !existsSync(root)) return
  const target = realpathSync(root)

  if (dirname(target) !== realpathSync(tmpdir()) || !basename(target).startsWith('makert-e2e-'))
    throw new Error('Refusing to remove a directory outside E2E temporary storage.')

  rmSync(target, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 })
}

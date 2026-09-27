/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đăng ký các MSW handler dùng cho dữ liệu giả frontend
 * =====================================================================
 *
 * File tập hợp handler theo từng feature và bật worker khi biến môi trường
 * `VITE_ENABLE_MSW` được đặt thành `true`. Handler resource admin được đăng ký
 * tại đây để page VDataTableServer có thể dùng fake data trước API thật.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - default(): khởi động MSW worker khi fake API được bật
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : biến môi trường VITE_ENABLE_MSW và BASE_URL của ứng dụng.
 * - OUTPUT: MSW worker với toàn bộ handler của các module frontend.
 * =====================================================================
 */
import { setupWorker } from 'msw/browser'

// Handlers
import { handlerAppBarSearch } from '@db/app-bar-search/index'
import { handlerAppsAcademy } from '@db/apps/academy/index'
import { handlerAppsCalendar } from '@db/apps/calendar/index'
import { handlerAppsChat } from '@db/apps/chat/index'
import { handlerAppsEcommerce } from '@db/apps/ecommerce/index'
import { handlerAppsEmail } from '@db/apps/email/index'
import { handlerAppsInvoice } from '@db/apps/invoice/index'
import { handlerAppsKanban } from '@db/apps/kanban/index'
import { handlerAppLogistics } from '@db/apps/logistics/index'
import { handlerAppsPermission } from '@db/apps/permission/index'
import { handlerAppsResources } from '@db/apps/resources/index'
import { handlerAppsUsers } from '@db/apps/users/index'
import { handlerDashboard } from '@db/dashboard/index'
import { handlerPagesDatatable } from '@db/pages/datatable/index'
import { handlerPagesFaq } from '@db/pages/faq/index'
import { handlerPagesHelpCenter } from '@db/pages/help-center/index'
import { handlerPagesProfile } from '@db/pages/profile/index'

const worker = setupWorker(...handlerAppsEcommerce, ...handlerAppsAcademy, ...handlerAppsInvoice, ...handlerAppsUsers, ...handlerAppsEmail, ...handlerAppsCalendar, ...handlerAppsChat, ...handlerAppsPermission, ...handlerAppsResources, ...handlerPagesHelpCenter, ...handlerPagesProfile, ...handlerPagesFaq, ...handlerPagesDatatable, ...handlerAppBarSearch, ...handlerAppLogistics, ...handlerAppsKanban, ...handlerDashboard)

/**
 * Khởi động MSW worker khi ứng dụng được cấu hình dùng fake API.
 *
 * Input: không có tham số; đọc VITE_ENABLE_MSW và BASE_URL từ Vite.
 * Output: Promise khởi động worker hoặc undefined khi fake API bị tắt.
 * Side effect: đăng ký service worker trong trình duyệt.
 */
export default function () {
  if (import.meta.env.VITE_ENABLE_MSW !== 'true')
    return

  const workerUrl = `${import.meta.env.BASE_URL.replace(/build\/$/g, '') ?? '/'}mockServiceWorker.js`

  worker.start({
    serviceWorker: {
      url: workerUrl,
    },
    onUnhandledRequest: 'bypass',
  })
}

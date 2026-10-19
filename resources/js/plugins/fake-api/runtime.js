/**
 * =====================================================================
 * CHỨC NĂNG FILE: Khởi tạo MSW và handler khi chế độ dữ liệu mẫu được bật.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - startFakeApi(): tạo worker và chờ đăng ký service worker.
 * INPUT/OUTPUT CỦA FILE (tổng thể): URL service worker -> Promise khởi động MSW.
 * Chỉ được import động từ plugin khi VITE_ENABLE_MSW=true.
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
import { handlerAppsMedia } from '@db/apps/media/index'
import { handlerAppLogistics } from '@db/apps/logistics/index'
import { handlerAppsResources } from '@db/apps/resources/index'
import { handlerAppsResourceVersions } from '@db/apps/resourceVersions/index'
import { handlerAppsPosts } from '@db/apps/posts/index'
import { handlerAppsUsers } from '@db/apps/users/index'
import { handlerDashboard } from '@db/dashboard/index'
import { handlerPagesDatatable } from '@db/pages/datatable/index'
import { handlerPagesFaq } from '@db/pages/faq/index'
import { handlerPagesHelpCenter } from '@db/pages/help-center/index'
import { handlerPagesProfile } from '@db/pages/profile/index'


/** Input: URL service worker. Output: Promise đăng ký worker với handler đã có. */
export function startFakeApi(workerUrl) {
  const worker = setupWorker(...handlerAppsEcommerce, ...handlerAppsAcademy, ...handlerAppsInvoice, ...handlerAppsUsers, ...handlerAppsEmail, ...handlerAppsCalendar, ...handlerAppsChat, ...handlerAppsResources, ...handlerAppsResourceVersions, ...handlerAppsPosts, ...handlerAppsMedia, ...handlerPagesHelpCenter, ...handlerPagesProfile, ...handlerPagesFaq, ...handlerPagesDatatable, ...handlerAppBarSearch, ...handlerAppLogistics, ...handlerAppsKanban, ...handlerDashboard)

  return worker.start({
    serviceWorker: { url: workerUrl },
    onUnhandledRequest: 'bypass',
  })
}

import { useAdminAuthStore } from '@/stores/adminAuth'
import { store } from '@/plugins/2.pinia'

const emailRouteComponent = () => import('@/pages/apps/email/index.vue')

// 👉 Redirects
export const redirects = [
  // ℹ️ We are redirecting to different pages based on role.
  // NOTE: Role is just for UI purposes. ACL is based on abilities.
  {
    path: '/',
    name: 'index',
    redirect: to => {
      const adminAuth = useAdminAuthStore(store)
      if (adminAuth.isAuthenticated)
        return { name: 'dashboards-crm' }

      return { name: 'login', query: to.query }
    },
  },
  {
    path: '/pages/user-profile',
    name: 'pages-user-profile',
    redirect: () => ({ name: 'pages-user-profile-tab', params: { tab: 'profile' } }),
  },
  {
    path: '/pages/account-settings',
    name: 'pages-account-settings',
    redirect: () => ({ name: 'pages-account-settings-tab', params: { tab: 'account' } }),
  },

  // Legacy Media Demo URLs remain available for existing bookmarks.
  {
    path: '/apps/media/demo',
    name: 'apps-media-demo',
    redirect: to => ({ name: 'apps-media-media-asset', query: to.query }),
  },
  {
    path: '/apps/media/demo/folder/:folder',
    name: 'apps-media-demo-folder',
    redirect: to => ({
      name: 'apps-media-media-asset-folder',
      params: { folder: to.params.folder },
      query: to.query,
    }),
  },
]
export const routes = [
  // Email filter
  {
    path: '/apps/email/filter/:filter',
    name: 'apps-email-filter',
    component: emailRouteComponent,
    meta: {
      navActiveLink: 'apps-email',
      layoutWrapperClasses: 'layout-content-height-fixed',
    },
  },

  // Email label
  {
    path: '/apps/email/label/:label',
    name: 'apps-email-label',
    component: emailRouteComponent,
    meta: {
      // contentClass: 'email-application',
      navActiveLink: 'apps-email',
      layoutWrapperClasses: 'layout-content-height-fixed',
    },
  },

  // Media Asset folder
  {
    path: '/apps/media/media-asset/folder/:folder',
    name: 'apps-media-media-asset-folder',
    component: () => import('@/pages/apps/media/media-asset/index.vue'),
    beforeEnter: to => {
      const validFolders = ['images', 'videos', 'documents', 'trash']

      if (validFolders.includes(to.params.folder))
        return true

      return { name: 'apps-media-media-asset', query: to.query }
    },
    meta: {
      navActiveLink: 'apps-media-media-asset',
      layoutWrapperClasses: 'layout-content-height-fixed',
    },
  },

  {
    path: '/dashboards/logistics',
    name: 'dashboards-logistics',
    component: () => import('@/pages/apps/logistics/dashboard.vue'),
  },
  {
    path: '/dashboards/academy',
    name: 'dashboards-academy',
    component: () => import('@/pages/apps/academy/dashboard.vue'),
  },
  {
    path: '/apps/ecommerce/dashboard',
    name: 'apps-ecommerce-dashboard',
    component: () => import('@/pages/dashboards/ecommerce.vue'),
  },
]

<!--
  =====================================================================
  CHỨC NĂNG FILE: Cung cấp màn hình đăng nhập quản trị bằng Sanctum Bearer token
  =====================================================================

  Page sử dụng layout auth hoàn chỉnh của template Vuexy, đồng thời gọi admin
  auth store để xác thực bằng API Laravel và chuyển người dùng về route đã yêu
  cầu hoặc dashboard mặc định sau khi đăng nhập.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - login(): gửi thông tin đăng nhập và xử lý redirect/lỗi API
  - onSubmit(): validate VForm trước khi gọi login()

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : credentials từ form và query `to` của route hiện tại
  - OUTPUT: trạng thái loading/lỗi; Sanctum session trong store; điều hướng sau login
  =====================================================================
-->
<script setup>
import { reactive, shallowRef } from 'vue'
import { VForm } from 'vuetify/components/VForm'
import AuthProvider from '@/views/pages/authentication/AuthProvider.vue'
import { useAdminAuthStore } from '@/stores/adminAuth'
import { useGenerateImageVariant } from '@core/composable/useGenerateImageVariant'
import authV2LoginIllustrationBorderedDark from '@images/pages/auth-v2-login-illustration-bordered-dark.png'
import authV2LoginIllustrationBorderedLight from '@images/pages/auth-v2-login-illustration-bordered-light.png'
import authV2LoginIllustrationDark from '@images/pages/auth-v2-login-illustration-dark.png'
import authV2LoginIllustrationLight from '@images/pages/auth-v2-login-illustration-light.png'
import authV2MaskDark from '@images/pages/misc-mask-dark.png'
import authV2MaskLight from '@images/pages/misc-mask-light.png'
import { VNodeRenderer } from '@layouts/components/VNodeRenderer'
import { themeConfig } from '@themeConfig'

definePage({
  meta: {
    layout: 'blank',
    unauthenticatedOnly: true,
  },
})

const authThemeImg = useGenerateImageVariant(authV2LoginIllustrationLight, authV2LoginIllustrationDark, authV2LoginIllustrationBorderedLight, authV2LoginIllustrationBorderedDark, true)
const authThemeMask = useGenerateImageVariant(authV2MaskLight, authV2MaskDark)
const route = useRoute()
const router = useRouter()
const adminAuth = useAdminAuthStore()
const refVForm = shallowRef()
const errors = shallowRef({})
const formError = shallowRef('')
const isLoading = shallowRef(false)
const isPasswordVisible = shallowRef(false)
const rememberMe = shallowRef(false)

const credentials = reactive({
  email: '',
  password: '',
})

/**
 * Đăng nhập admin và điều hướng đến URL nội bộ an toàn sau khi thành công.
 *
 * INPUT: credentials gồm email/password và route query `to` tùy chọn.
 * OUTPUT: Promise hoàn tất sau khi đăng nhập hoặc sau khi đã hiển thị lỗi.
 * SIDE EFFECT: Gọi API, cập nhật admin auth store, errors/loading và Vue Router.
 * EXCEPTION: Lỗi API được chuyển thành validation message, không ném tiếp ra UI.
 */
const login = async () => {
  isLoading.value = true
  errors.value = {}
  formError.value = ''

  try {
    await adminAuth.login({
      email: credentials.email,
      password: credentials.password,
      deviceName: 'admin-web',
    })

    const redirectPath = typeof route.query.to === 'string'
      && route.query.to.startsWith('/')
      && !route.query.to.startsWith('//')
      ? route.query.to
      : '/'

    await router.replace(redirectPath)
  }
  catch (error) {
    const payload = error?.data ?? error?.response?._data ?? {}

    errors.value = payload.errors ?? {}
    formError.value = payload.message ?? 'Thông tin đăng nhập không hợp lệ.'
  }
  finally {
    isLoading.value = false
  }
}

/**
 * Xác thực form trước khi bắt đầu request đăng nhập.
 *
 * INPUT: trạng thái hiện tại của VForm qua refVForm.
 * OUTPUT: Không trả dữ liệu.
 * SIDE EFFECT: Gọi login() khi toàn bộ rule của form hợp lệ.
 */
const onSubmit = async () => {
  const validation = await refVForm.value?.validate()

  if (validation?.valid)
    await login()
}
</script>

<template>
  <RouterLink to="/">
    <div class="auth-logo d-flex align-center gap-x-3">
      <VNodeRenderer :nodes="themeConfig.app.logo" />
      <h1 class="auth-title">
        {{ themeConfig.app.title }}
      </h1>
    </div>
  </RouterLink>

  <VRow
    no-gutters
    class="auth-wrapper bg-surface"
  >
    <VCol
      md="8"
      class="d-none d-md-flex"
    >
      <div class="position-relative bg-background w-100 me-0">
        <div
          class="d-flex align-center justify-center w-100 h-100"
          style="padding-inline: 6.25rem;"
        >
          <VImg
            max-width="613"
            :src="authThemeImg"
            class="auth-illustration mt-16 mb-2"
          />
        </div>

        <img
          class="auth-footer-mask"
          :src="authThemeMask"
          alt="auth-footer-mask"
          height="280"
          width="100"
        >
      </div>
    </VCol>

    <VCol
      cols="12"
      md="4"
      class="auth-card-v2 d-flex align-center justify-center"
    >
      <VCard
        flat
        :max-width="500"
        class="mt-12 mt-sm-0 pa-4"
      >
        <VCardText>
          <h4 class="text-h4 mb-1">
            Welcome to <span class="text-capitalize"> {{ themeConfig.app.title }} </span>! 👋🏻
          </h4>
          <p class="mb-0">
            Đăng nhập bằng tài khoản quản trị để tiếp tục.
          </p>
        </VCardText>
        <VCardText>
          <VAlert
            color="primary"
            variant="tonal"
          >
            <p class="text-sm mb-0">
              Sử dụng tài khoản quản trị được cấp để truy cập bảng điều khiển.
            </p>
          </VAlert>
        </VCardText>
        <VCardText>
          <VAlert
            v-if="formError"
            color="error"
            variant="tonal"
            class="mb-4"
          >
            {{ formError }}
          </VAlert>
          <VForm
            ref="refVForm"
            @submit.prevent="onSubmit"
          >
            <VRow>
              <VCol cols="12">
                <AppTextField
                  v-model="credentials.email"
                  label="Email"
                  placeholder="admin@example.com"
                  type="email"
                  autofocus
                  :rules="[requiredValidator, emailValidator]"
                  :error-messages="errors.email"
                />
              </VCol>

              <VCol cols="12">
                <AppTextField
                  v-model="credentials.password"
                  label="Password"
                  placeholder="············"
                  :rules="[requiredValidator]"
                  :type="isPasswordVisible ? 'text' : 'password'"
                  autocomplete="current-password"
                  :error-messages="errors.password"
                  :append-inner-icon="isPasswordVisible ? 'tabler-eye-off' : 'tabler-eye'"
                  @click:append-inner="isPasswordVisible = !isPasswordVisible"
                />

                <div class="d-flex align-center flex-wrap justify-space-between my-6">
                  <VCheckbox
                    v-model="rememberMe"
                    label="Remember me"
                  />
                  <RouterLink
                    class="text-primary ms-2 mb-1"
                    :to="{ name: 'pages-authentication-forgot-password-v2' }"
                  >
                    Forgot Password?
                  </RouterLink>
                </div>

                <VBtn
                  block
                  type="submit"
                  :loading="isLoading"
                  :disabled="isLoading"
                >
                  Login
                </VBtn>
              </VCol>

              <VCol
                cols="12"
                class="text-center"
              >
                <span>New on our platform?</span>
                <RouterLink
                  class="text-primary ms-1"
                  :to="{ name: 'pages-authentication-register-v2' }"
                >
                  Create an account
                </RouterLink>
              </VCol>
              <VCol
                cols="12"
                class="d-flex align-center"
              >
                <VDivider />
                <span class="mx-4">or</span>
                <VDivider />
              </VCol>

              <VCol
                cols="12"
                class="text-center"
              >
                <AuthProvider />
              </VCol>
            </VRow>
          </VForm>
        </VCardText>
      </VCard>
    </VCol>
  </VRow>
</template>

<style lang="scss">
@use "@core-scss/template/pages/page-auth";
</style>

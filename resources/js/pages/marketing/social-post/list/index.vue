<!--
  =====================================================================
  CHỨC NĂNG FILE: Trang quản lý Social Post trong Marketing.
  =====================================================================
  Route view chỉ ghép filter, dialog và card; dữ liệu lấy từ API Social Post,
  không còn fixture hoặc luồng video trong MVP.

  CÁC HÀM/METHOD TRONG FILE:
  - openCreate()/closeCreate(): điều khiển form tạo draft.
  - handleCreate(): gửi form tới composable.
  - handleAction(): dispatch action card và reload list.

  INPUT/OUTPUT CỦA COMPONENT:
  - INPUT : query/filter từ người dùng.
  - OUTPUT: Marketing Social Post list và các mutation lifecycle.
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase -- Query keys follow the Laravel Social Post contract. */
import { computed, onMounted, shallowRef, watch } from 'vue'
import SocialPostCard from '@/views/marketing/social-post/components/SocialPostCard.vue'
import SocialPostCreateDialog from '@/views/marketing/social-post/components/SocialPostCreateDialog.vue'
import SocialPostDetailDialog from '@/views/marketing/social-post/components/SocialPostDetailDialog.vue'
import { useSocialPosts } from '@/composables/useSocialPosts'

definePage({ meta: { navActiveLink: 'marketing-social-post-list' } })

const createOpen = shallowRef(false)
const selectedPlatform = shallowRef('')
const selectedReview = shallowRef('')
const search = shallowRef('')
const actionLoading = shallowRef(false)
const notice = shallowRef('')
const detailOpen = shallowRef(false)
const selectedPostId = shallowRef(null)

const {
  items, stats, products, campaigns, accounts, loading, error, page, itemsPerPage, total, filters,
  load, create, generate, regenerateCaption, regenerateImage, submitReview, approve, reject, publish,
} = useSocialPosts()

const platformOptions = [
  { title: 'Tất cả nền tảng', value: '' },
  { title: 'Facebook', value: 'facebook' },
]

const reviewOptions = [
  { title: 'Tất cả trạng thái', value: '' },
  { title: 'Draft', value: 'draft' },
  { title: 'Chờ duyệt', value: 'pending_review' },
  { title: 'Đã duyệt', value: 'approved' },
  { title: 'Từ chối', value: 'rejected' },
]

const statCards = computed(() => [
  { label: 'Draft', value: stats.value.draft ?? 0, icon: 'tabler-file-pencil', color: 'secondary' },
  { label: 'Chờ duyệt', value: stats.value.pending_review ?? 0, icon: 'tabler-hourglass', color: 'warning' },
  { label: 'Đã duyệt', value: stats.value.approved ?? 0, icon: 'tabler-circle-check', color: 'success' },
  { label: 'Đã đăng', value: stats.value.published ?? 0, icon: 'tabler-brand-facebook', color: 'info' },
])

/** Input: mở form. Output: dialog visible. */
function openCreate() { createOpen.value = true }

/** Input: dialog close. Output: dialog hidden. */
function closeCreate() { createOpen.value = false }

/** Input: card post ID. Output: detail/version dialog visible. */
function openDetail(id) {
  selectedPostId.value = id
  detailOpen.value = true
}

/** Input: create form payload. Output: draft queued and list refreshed. */
async function handleCreate(payload) {
  actionLoading.value = true
  try {
    if (action === 'view') {
      openDetail(id)

      return
    }
    await create(payload)
    notice.value = 'Đã tạo draft Social Post và xếp hàng AI.'
    closeCreate()
  } finally {
    actionLoading.value = false
  }
}

/** Input: filter selection. Output: composable query updated. */
function syncFilters() {
  filters.value = { ...filters.value, search: search.value, platform: selectedPlatform.value, review_status: selectedReview.value }
  page.value = 1
  load()
}

/** Input: card action/id. Output: API lifecycle mutation. */
async function handleAction(action, id) {
  actionLoading.value = true
  notice.value = ''
  try {
    if (action === 'generate') await generate(id)
    if (action === 'regenerate-caption') await regenerateCaption(id)
    if (action === 'regenerate-image') await regenerateImage(id)
    if (action === 'submit-review') await submitReview(id)
    if (action === 'approve') await approve(id)
    if (action === 'publish') await publish(id)
    if (action === 'reject') await reject(id, 'Cần chỉnh sửa trước khi đăng.')
    notice.value = 'Đã cập nhật Social Post.'
  } finally {
    actionLoading.value = false
  }
}

watch([search, selectedPlatform, selectedReview], syncFilters)
watch([page, itemsPerPage], load)
onMounted(load)
</script>

<template>
  <div class="social-posts-page">
    <VRow class="mb-5">
      <VCol cols="12">
        <div class="d-flex flex-wrap justify-space-between align-center gap-4">
          <div>
            <h4 class="text-h4">
              Social Post
            </h4><p class="text-body-1 text-medium-emphasis mb-0">
              Tạo caption và ảnh bằng AI, duyệt thủ công rồi đăng Facebook.
            </p>
          </div><VBtn
            prepend-icon="tabler-plus"
            color="primary"
            @click="openCreate"
          >
            Tạo bài viết
          </VBtn>
        </div>
      </VCol>
    </VRow>

    <VAlert
      v-if="error"
      type="error"
      variant="tonal"
      closable
      class="mb-5"
    >
      {{ error }}
    </VAlert>
    <VAlert
      v-if="notice"
      type="success"
      variant="tonal"
      closable
      class="mb-5"
    >
      {{ notice }}
    </VAlert>

    <VRow class="mb-5">
      <VCol
        v-for="stat in statCards"
        :key="stat.label"
        cols="12"
        sm="6"
        lg="3"
      >
        <VCard>
          <VCardText class="d-flex align-center gap-3">
            <VAvatar
              :color="stat.color"
              variant="tonal"
              rounded="lg"
            >
              <VIcon :icon="stat.icon" />
            </VAvatar><div>
              <div class="text-caption text-medium-emphasis">
                {{ stat.label }}
              </div><div class="text-h5 font-weight-bold">
                {{ stat.value }}
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard class="mb-5">
      <VCardText class="d-flex flex-wrap align-center gap-3">
        <AppTextField
          v-model="search"
          placeholder="Tìm trong caption"
          prepend-inner-icon="tabler-search"
          clearable
          hide-details
          class="social-posts-page__search"
        /><VSelect
          v-model="selectedPlatform"
          :items="platformOptions"
          label="Nền tảng"
          density="compact"
          hide-details
          class="social-posts-page__filter"
        /><VSelect
          v-model="selectedReview"
          :items="reviewOptions"
          label="Trạng thái duyệt"
          density="compact"
          hide-details
          class="social-posts-page__filter"
        /><VSpacer /><VBtn
          icon="tabler-refresh"
          variant="tonal"
          :loading="loading"
          aria-label="Tải lại"
          @click="load"
        />
      </VCardText>
    </VCard>

    <div
      v-if="loading && !items.length"
      class="d-flex justify-center py-12"
    >
      <VProgressCircular
        indeterminate
        color="primary"
      />
    </div>
    <VRow v-else-if="items.length">
      <VCol
        v-for="post in items"
        :key="post.id"
        cols="12"
        sm="6"
        lg="4"
        xl="3"
      >
        <SocialPostCard
          :post="post"
          @view="handleAction('view', $event)"
          @generate="handleAction('generate', $event)"
          @regenerate-caption="handleAction('regenerate-caption', $event)"
          @regenerate-image="handleAction('regenerate-image', $event)"
          @submit-review="handleAction('submit-review', $event)"
          @approve="handleAction('approve', $event)"
          @publish="handleAction('publish', $event)"
          @reject="handleAction('reject', $event)"
        />
      </VCol>
    </VRow>
    <VCard v-else>
      <VCardText class="text-center py-12 text-medium-emphasis">
        <VIcon
          icon="tabler-file-off"
          size="40"
          class="mb-3"
        /><div>Chưa có Social Post nào.</div><VBtn
          class="mt-4"
          color="primary"
          @click="openCreate"
        >
          Tạo draft đầu tiên
        </VBtn>
      </VCardText>
    </VCard>

    <div
      v-if="total > itemsPerPage"
      class="d-flex justify-space-between align-center mt-6"
    >
      <span class="text-caption text-medium-emphasis">{{ total }} Social Post</span><div class="d-flex align-center gap-3">
        <AppSelect
          v-model="itemsPerPage"
          :items="[12, 24, 48]"
          density="compact"
          hide-details
        /><VPagination
          v-model="page"
          :length="Math.ceil(total / itemsPerPage)"
          total-visible="5"
        />
      </div>
    </div>

    <SocialPostCreateDialog
      v-model="createOpen"
      :products="products"
      :campaigns="campaigns"
      :accounts="accounts"
      @submit="handleCreate"
      @update:model-value="value => { if (!value) closeCreate() }"
    />
    <SocialPostDetailDialog
      v-model="detailOpen"
      :post-id="selectedPostId"
      @saved="load"
    />
    <VOverlay
      v-model="actionLoading"
      class="align-center justify-center"
      persistent
    >
      <VProgressCircular
        indeterminate
        color="primary"
      />
    </VOverlay>
  </div>
</template>

<style scoped>
.social-posts-page__search { max-width: 360px; min-width: 220px; }
.social-posts-page__filter { min-width: 190px; max-width: 220px; }
</style>

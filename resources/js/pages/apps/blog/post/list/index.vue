<!--
  =====================================================================
  CHỨC NĂNG FILE: Trang danh sách Post và điều hướng tạo/chỉnh sửa bài viết.
  =====================================================================

  Trang tải danh sách Post qua Pinia store và điều hướng tới PostForm.
  Bộ lọc ở panel riêng phía trên; bảng dữ liệu và lỗi tải nằm ở panel phía dưới.
  Việc tạo nội dung bằng AI được quản lý tại trang AI Content.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - loadPosts(): tải danh sách theo trang, tìm kiếm, trạng thái, tác giả và ngày.
  - handleWorkflow(): gọi submit review/publish/archive rồi tải lại bảng.
  - watcher page/itemsPerPage/search/status/author/date: tải lại Post list khi query thay đổi.
  - errorMessage/authorItems/dateRangeError: lỗi API, option tác giả và lỗi khoảng ngày.
  - onMounted(): tải tác giả để dựng bộ lọc; có thao tác tải lại nếu API lỗi.

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : route, Post store và trạng thái query của bảng.
  - OUTPUT: bảng Post và điều hướng Add/Edit Post.
  =====================================================================
-->
<script setup>
/* eslint-disable camelcase -- Query keys follow the Laravel API contract. */
import { computed, onMounted, shallowRef, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useAdminAuthStore } from '@/stores/adminAuth'
import { usePostStore } from '@/stores/post'

const router = useRouter()
const store = usePostStore()
const auth = useAdminAuthStore()
const { items, authors, totalItems, isLoading, error, isAuthorsLoading, authorsError } = storeToRefs(store)
const page = shallowRef(1)
const itemsPerPage = shallowRef(15)
const search = shallowRef('')
const status = shallowRef(null)
const author = shallowRef(null)
const createdFrom = shallowRef('')
const createdTo = shallowRef('')
const errorMessage = computed(() => error.value?.data?.message || error.value?.response?._data?.message || 'Không thể tải bài viết.')
const authorItems = computed(() => authors.value.map(item => ({ title: item.name || item.email, value: item.id })))

const dateRangeError = computed(() => createdFrom.value && createdTo.value && createdFrom.value > createdTo.value
  ? 'Ngày kết thúc phải lớn hơn hoặc bằng ngày bắt đầu.'
  : '')

const datePickerConfig = { dateFormat: 'Y-m-d', altInput: true, altFormat: 'd/m/Y', enableTime: false }

const elevatedRoles = ['admin', 'super-admin', 'administrator']

const can = permission => auth.permissions.includes(permission)
  || auth.permissions.includes('posts.manage')
  || auth.roles.some(role => elevatedRoles.includes(role))

const reloadAuthors = () => store.fetchPostAuthors()

/**
 * =====================================================================
 * CHỨC NĂNG: Tải bảng theo bộ lọc hợp lệ hiện tại
 * =====================================================================
 * INPUT: filter/page từ giao diện, ngày được picker trả dạng Y-m-d.
 * OUTPUT: promise tải danh sách; không gửi khoảng ngày đảo chiều.
 * SIDE EFFECT: gọi Pinia store, backend kiểm quyền và validation lần cuối.
 * EXCEPTION/TRANSACTION: store lưu lỗi API để UI hiển thị và tải lại.
 * =====================================================================
 */
const loadPosts = () => {
  if (dateRangeError.value) return

  return store.fetchPosts({
    page: page.value,
    'per_page': itemsPerPage.value,
    search: search.value || undefined,
    status: status.value || undefined,
    author_id: author.value || undefined,
    created_from: createdFrom.value || undefined,
    created_to: createdTo.value || undefined,
  })
}

/**
 * =====================================================================
 * CHỨC NĂNG: Chạy lifecycle action trên một Post rồi tải lại bảng
 * =====================================================================
 * INPUT: row Post và tên action đã expose từ store.
 * OUTPUT: không trả dữ liệu cho template.
 * SIDE EFFECT: gọi API lifecycle; bảng reload sau khi thành công.
 * EXCEPTION: store giữ lỗi API để panel bảng hiển thị.
 * =====================================================================
 */
const handleWorkflow = async (post, action) => {
  if (store.isMutating) return
  try {
    await store[action](post.id)
    await loadPosts()
  }
  catch {}
}

watch([search, status, author, createdFrom, createdTo, itemsPerPage], () => {
  if (page.value !== 1) page.value = 1
  else void loadPosts()
})
onMounted(() => void store.fetchPostAuthors())
watch(page, () => void loadPosts(), { immediate: true })
</script>

<template>
  <div>
    <div class="d-flex flex-wrap justify-space-between gap-y-4 mb-6">
      <div>
        <h4 class="text-h4 font-weight-medium">
          Posts
        </h4><div class="text-body-1">
          Quản lý nội dung và media của bài viết.
        </div>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <VBtn
          prepend-icon="tabler-plus"
          @click="router.push({ name: 'apps-blog-post-add' })"
        >
          Add Post
        </VBtn>
      </div>
    </div>
    <VCard
      title="Bộ lọc bài viết"
      class="mb-6"
    >
      <VCardText>
        <VRow>
          <VCol
            cols="12"
            md="4"
          >
            <AppTextField
              v-model="search"
              placeholder="Search posts"
              clearable
            />
          </VCol>
          <VCol
            cols="12"
            md="4"
          >
            <AppSelect
              v-model="status"
              label="Status"
              :items="[{ title: 'Draft', value: 'draft' }, { title: 'Pending review', value: 'pending_review' }, { title: 'Rejected', value: 'rejected' }, { title: 'Published', value: 'published' }, { title: 'Archived', value: 'archived' }]"
              clearable
            />
          </VCol>
          <VCol
            cols="12"
            md="4"
          >
            <AppSelect
              v-model="author"
              label="Author"
              :items="authorItems"
              :loading="isAuthorsLoading"
              clearable
            />
          </VCol>
        </VRow>
        <VRow>
          <VCol
            cols="12"
            md="6"
          >
            <AppDateTimePicker
              v-model="createdFrom"
              label="Từ ngày tạo"
              placeholder="Chọn ngày bắt đầu"
              :config="datePickerConfig"
              clearable
            />
          </VCol>
          <VCol
            cols="12"
            md="6"
          >
            <AppDateTimePicker
              v-model="createdTo"
              label="Đến ngày tạo"
              placeholder="Chọn ngày kết thúc"
              :config="datePickerConfig"
              :error-messages="dateRangeError"
              clearable
            />
          </VCol>
        </VRow>
        <VAlert
          v-if="authorsError"
          color="error"
          variant="tonal"
          class="mt-4"
        >
          Không thể tải danh sách tác giả.
          <template #append>
            <VBtn
              variant="text"
              :loading="isAuthorsLoading"
              @click="reloadAuthors"
            >
              Tải lại tác giả
            </VBtn>
          </template>
        </VAlert>
      </VCardText>
    </VCard>
    <VCard>
      <VAlert
        v-if="error"
        color="error"
        variant="tonal"
        class="ma-4"
      >
        {{ errorMessage }}
        <template #append>
          <VBtn
            variant="text"
            @click="loadPosts"
          >
            Tải lại bài viết
          </VBtn>
        </template>
      </VAlert>
      <VDataTableServer
        v-model:items-per-page="itemsPerPage"
        v-model:page="page"
        :items="items"
        :items-length="totalItems"
        :loading="isLoading"
        no-data-text="Không có bài viết phù hợp với bộ lọc."
        :headers="[{ title: 'Title', key: 'title' }, { title: 'Author', key: 'author' }, { title: 'Status', key: 'status' }, { title: 'Thumbnail', key: 'media.thumbnail' }, { title: 'Actions', key: 'actions', sortable: false }]"
      >
        <template #item.author="{ item }">
          {{ item.author?.name || item.author?.email || '—' }}
        </template>
        <template #item.status="{ item }">
          <VChip
            size="small"
            :color="item.status === 'published' ? 'success' : item.status === 'rejected' ? 'error' : undefined"
          >
            {{ item.status.replace('_', ' ') }}
          </VChip>
        </template>
        <template #item.media.thumbnail="{ item }">
          {{ item.media?.thumbnail?.title || '—' }}
        </template>
        <template #item.actions="{ item }">
          <VBtn
            icon="tabler-edit"
            variant="text"
            size="small"
            @click="router.push({ name: 'apps-blog-post-add', query: { post: item.id } })"
          />
          <VBtn
            v-if="['draft', 'rejected'].includes(item.status) && can('posts.review')"
            icon="tabler-eye-check"
            variant="text"
            size="small"
            title="Submit for review"
            :disabled="isLoading"
            @click="handleWorkflow(item, 'submitPostReview')"
          />
          <VBtn
            v-if="['draft', 'pending_review', 'rejected'].includes(item.status) && can('posts.publish')"
            icon="tabler-send"
            variant="text"
            size="small"
            title="Publish"
            :disabled="isLoading"
            @click="handleWorkflow(item, 'publishPost')"
          />
          <VBtn
            v-if="item.status !== 'archived' && can('posts.archive')"
            icon="tabler-archive"
            variant="text"
            size="small"
            title="Archive"
            :disabled="isLoading"
            @click="handleWorkflow(item, 'archivePost')"
          />
        </template>
      </VDataTableServer>
    </VCard>
  </div>
</template>

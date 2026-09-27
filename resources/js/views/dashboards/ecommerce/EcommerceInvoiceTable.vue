<!--
  =====================================================================
  CHỨC NĂNG FILE: Hiển thị bảng hóa đơn trong dashboard thương mại điện tử
  =====================================================================

  Component tải danh sách hóa đơn theo bộ lọc, phân trang và sắp xếp. Dữ liệu
  có thể đến từ Laravel API hoặc MSW local; khi request lỗi, component giữ UI
  ổn định với danh sách rỗng, cảnh báo và nút retry thay vì truy cập null.

  CÁC HÀM/COMPUTED/WATCHER TRONG FILE:
  - updateOptions(options): cập nhật page/sort từ VDataTableServer
  - invoices: chuẩn hóa danh sách hóa đơn về mảng an toàn
  - totalInvoices: chuẩn hóa tổng số bản ghi về số an toàn
  - resolveInvoiceBalanceVariant(balance, total): xác định nhãn/màu số dư
  - resolveInvoiceStatusVariantAndIcon(status): xác định màu/icon trạng thái
  - computedMoreList(paramId): tạo menu thao tác cho từng hóa đơn
  - deleteInvoice(id): xóa hóa đơn và tải lại danh sách
  - retryInvoices(): gọi lại request sau khi tải dữ liệu thất bại

  INPUT/OUTPUT CỦA COMPONENT (tổng thể):
  - INPUT : query/filter state, response `/api/apps/invoice`, retry action
  - OUTPUT: bảng hóa đơn, loading/error/empty state và navigation tới invoice pages
  =====================================================================
-->
<script setup>
const searchQuery = ref('')
const selectedStatus = ref(null)
const selectedRows = ref([])

// Data table options
const itemsPerPage = ref(6)
const page = ref(1)
const sortBy = ref()
const orderBy = ref()

/**
 * Đồng bộ phân trang và sắp xếp từ VDataTableServer vào query state.
 *
 * INPUT: options do VDataTableServer phát ra, có thể thiếu sortBy khi chưa sắp xếp.
 * OUTPUT: Không trả dữ liệu.
 * SIDE EFFECT: Cập nhật page, sortBy và orderBy để useApi refetch.
 */
const updateOptions = options => {
  page.value = options.page
  sortBy.value = options.sortBy[0]?.key
  orderBy.value = options.sortBy[0]?.order
}

// 👉 headers
const headers = [
  {
    title: '#',
    key: 'id',
  },
  {
    title: 'Status',
    key: 'status',
    sortable: false,
  },
  {
    title: 'Total',
    key: 'total',
  },
  {
    title: 'Issued Date',
    key: 'date',
  },
  {
    title: 'Balance',
    key: 'balance',
  },
  {
    title: 'Actions',
    key: 'actions',
    sortable: false,
  },
]

const {
  data: invoiceData,
  error: invoiceError,
  isFetching: isLoading,
  execute: fetchInvoices,
} = await useApi(createUrl('/apps/invoice', {
  query: {
    q: searchQuery,
    status: selectedStatus,
    itemsPerPage,
    page,
    sortBy,
    orderBy,
  },
}))

const invoices = computed(() => invoiceData.value?.invoices ?? [])
const totalInvoices = computed(() => invoiceData.value?.totalInvoices ?? 0)

/**
 * Chuyển số dư hóa đơn thành trạng thái hiển thị và màu chip.
 *
 * INPUT: balance và total là giá trị tiền của một hóa đơn.
 * OUTPUT: object gồm status và chip props cho template.
 * SIDE EFFECT: Không có.
 */
const resolveInvoiceBalanceVariant = (balance, total) => {
  if (balance === total)
    return {
      status: 'Unpaid',
      chip: { color: 'error' },
    }
  if (balance === 0)
    return {
      status: 'Paid',
      chip: { color: 'success' },
    }
  
  return {
    status: balance,
    chip: { variant: 'text' },
  }
}

/**
 * Chuyển trạng thái hóa đơn thành màu và icon Vuetify.
 *
 * INPUT: status chuỗi từ API.
 * OUTPUT: object gồm variant và icon; trạng thái lạ dùng fallback.
 * SIDE EFFECT: Không có.
 */
const resolveInvoiceStatusVariantAndIcon = status => {
  if (status === 'Partial Payment')
    return {
      variant: 'warning',
      icon: 'tabler-chart-pie-2',
    }
  if (status === 'Paid')
    return {
      variant: 'success',
      icon: 'tabler-check',
    }
  if (status === 'Downloaded')
    return {
      variant: 'info',
      icon: 'tabler-arrow-down',
    }
  if (status === 'Draft')
    return {
      variant: 'primary',
      icon: 'tabler-folder',
    }
  if (status === 'Sent')
    return {
      variant: 'secondary',
      icon: 'tabler-mail',
    }
  if (status === 'Past Due')
    return {
      variant: 'error',
      icon: 'tabler-help',
    }
  
  return {
    variant: 'secondary',
    icon: 'tabler-x',
  }
}

/**
 * Tạo danh sách thao tác cho menu của một hóa đơn.
 *
 * INPUT: paramId là id hóa đơn dùng cho route edit.
 * OUTPUT: computed function trả về các menu item.
 * SIDE EFFECT: Không có.
 */
const computedMoreList = computed(() => {
  return paramId => [
    {
      title: 'Download',
      value: 'download',
      prependIcon: 'tabler-download',
    },
    {
      title: 'Edit',
      value: 'edit',
      prependIcon: 'tabler-pencil',
      to: {
        name: 'apps-invoice-edit-id',
        params: { id: paramId },
      },
    },
    {
      title: 'Duplicate',
      value: 'duplicate',
      prependIcon: 'tabler-layers-intersect',
    },
  ]
})

/**
 * Xóa hóa đơn qua API rồi tải lại danh sách hiện tại.
 *
 * INPUT: id hóa đơn cần xóa.
 * OUTPUT: Promise hoàn tất sau request và lần refetch.
 * SIDE EFFECT: DELETE dữ liệu server/mock và cập nhật bảng.
 * EXCEPTION: Lỗi request được giữ cho API layer xử lý.
 */
const deleteInvoice = async id => {
  await $api(`/apps/invoice/${ id }`, { method: 'DELETE' })
  fetchInvoices()
}

/**
 * Tải lại danh sách hóa đơn mà không truyền DOM event vào execute().
 *
 * INPUT: Không có.
 * OUTPUT: Promise của request fetchInvoices.
 * SIDE EFFECT: Gửi lại GET `/apps/invoice` với query state hiện tại.
 */
const retryInvoices = () => fetchInvoices()
</script>

<template>
  <VCard id="invoice-list">
    <VAlert
      v-if="invoiceError"
      class="ma-4"
      type="warning"
      variant="tonal"
    >
      Không tải được dữ liệu hóa đơn. Hãy thử lại hoặc kiểm tra API backend.
      <template #append>
        <VBtn
          size="small"
          variant="text"
          @click="retryInvoices"
        >
          Thử lại
        </VBtn>
      </template>
    </VAlert>
    <VProgressLinear
      v-if="isLoading"
      indeterminate
      color="primary"
    />
    <VCardText>
      <div class="d-flex justify-space-between flex-wrap gap-4">
        <div class="d-flex gap-4 align-center">
          <div class="d-flex align-center gap-x-2">
            <div>
              Show
            </div>
            <AppSelect
              :model-value="itemsPerPage"
              :items="[
                { value: 6, title: '6' },
                { value: 10, title: '10' },
                { value: 25, title: '25' },
                { value: 50, title: '50' },
                { value: 100, title: '100' },
                { value: -1, title: 'All' },
              ]"
              @update:model-value="itemsPerPage = parseInt($event, 10)"
            />
          </div>
          <!-- 👉 Create invoice -->
          <VBtn
            prepend-icon="tabler-plus"
            :to="{ name: 'apps-invoice-add' }"
          >
            Create invoice
          </VBtn>
        </div>
        <div class="d-flex align-center flex-wrap gap-4">
          <!-- 👉 Search  -->
          <div class="invoice-list-filter">
            <AppTextField
              v-model="searchQuery"
              placeholder="Search Invoice"
            />
          </div>
          <!-- 👉 Select status -->
          <div class="invoice-list-filter">
            <AppSelect
              v-model="selectedStatus"
              placeholder="Select Status"
              clearable
              clear-icon="tabler-x"
              single-line
              :items="['Downloaded', 'Draft', 'Sent', 'Paid', 'Partial Payment', 'Past Due']"
            />
          </div>
        </div>
      </div>
    </VCardText>

    <VDivider />

    <!-- SECTION Datatable -->
    <VDataTableServer
      v-model="selectedRows"
      v-model:items-per-page="itemsPerPage"
      v-model:page="page"
      show-select
      :items-length="totalInvoices"
      :headers="headers"
      :items="invoices"
      item-value="id"
      class="text-no-wrap"
      @update:options="updateOptions"
    >
      <!-- id -->
      <template #item.id="{ item }">
        <RouterLink :to="{ name: 'apps-invoice-preview-id', params: { id: item.id } }">
          #{{ item.id }}
        </RouterLink>
      </template>

      <!-- status -->
      <template #item.status="{ item }">
        <VTooltip>
          <template #activator="{ props }">
            <VAvatar
              :size="28"
              v-bind="props"
              :color="resolveInvoiceStatusVariantAndIcon(item.invoiceStatus).variant"
              variant="tonal"
            >
              <VIcon
                :size="16"
                :icon="resolveInvoiceStatusVariantAndIcon(item.invoiceStatus).icon"
              />
            </VAvatar>
          </template>
          <p class="mb-0">
            {{ item.invoiceStatus }}
          </p>
          <p class="mb-0">
            Balance: {{ item.balance }}
          </p>
          <p class="mb-0">
            Due date: {{ item.dueDate }}
          </p>
        </VTooltip>
      </template>

      <!-- Total -->
      <template #item.total="{ item }">
        ${{ item.total }}
      </template>

      <!-- Date -->
      <template #item.date="{ item }">
        {{ item.issuedDate }}
      </template>

      <!-- Balance -->
      <template #item.balance="{ item }">
        <VChip
          v-if="typeof ((resolveInvoiceBalanceVariant(item.balance, item.total)).status) === 'string'"
          :color="resolveInvoiceBalanceVariant(item.balance, item.total).chip.color"
          label
          size="x-small"
        >
          {{ (resolveInvoiceBalanceVariant(item.balance, item.total)).status }}
        </VChip>

        <template v-else>
          <span class="text-base text-high-emphasis">
            {{ Number((resolveInvoiceBalanceVariant(item.balance, item.total)).status) > 0 ? `$${(resolveInvoiceBalanceVariant(item.balance, item.total)).status}` : `-$${Math.abs(Number((resolveInvoiceBalanceVariant(item.balance, item.total)).status))}` }}
          </span>
        </template>
      </template>

      <!-- Actions -->
      <template #item.actions="{ item }">
        <IconBtn @click="deleteInvoice(item.id)">
          <VIcon icon="tabler-trash" />
        </IconBtn>

        <IconBtn :to="{ name: 'apps-invoice-preview-id', params: { id: item.id } }">
          <VIcon icon="tabler-eye" />
        </IconBtn>

        <MoreBtn
          :menu-list="computedMoreList(item.id)"
          item-props
          color="undefined"
          size="small"
        />
      </template>

      <!-- pagination -->
      <template #bottom>
        <TablePagination
          v-model:page="page"
          :items-per-page="itemsPerPage"
          :total-items="totalInvoices"
        />
      </template>
    </VDataTableServer>
    <!-- !SECTION -->
  </VCard>
</template>

<style lang="scss">
#invoice-list {
  .invoice-list-actions {
    inline-size: 8rem;
  }

  .invoice-list-filter {
    inline-size: 12rem;
  }
}
</style>

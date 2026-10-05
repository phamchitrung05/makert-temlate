<!--
  =====================================================================
  CHỨC NĂNG FILE: ShareProjectDialog dùng khung dialog chung.
  CÁC HÀM/METHOD TRONG FILE: dialogVisibleUpdate.
  INPUT/OUTPUT CỦA CLASS (tổng thể): props/model và thao tác UI -> sự kiện của
  caller; header/footer cố định, content cuộn qua AppDialogLayout.
  =====================================================================
-->
<script setup>
import AppDialogLayout from '@/components/dialogs/AppDialogLayout.vue'
import avatar1 from '@images/avatars/avatar-1.png'
import avatar2 from '@images/avatars/avatar-2.png'
import avatar3 from '@images/avatars/avatar-3.png'
import avatar4 from '@images/avatars/avatar-4.png'
import avatar5 from '@images/avatars/avatar-5.png'
import avatar6 from '@images/avatars/avatar-6.png'
import avatar7 from '@images/avatars/avatar-7.png'
import avatar8 from '@images/avatars/avatar-8.png'

const props = defineProps({
  isDialogVisible: {
    type: Boolean,
    required: true,
  },
})

const emit = defineEmits(['update:isDialogVisible'])

const dialogVisibleUpdate = val => {
  emit('update:isDialogVisible', val)
}

const membersList = [
  {
    avatar: avatar1,
    name: 'Lester Palmer',
    email: 'jerrod98@gmail.com',
    permission: 'Can Edit',
  },
  {
    avatar: avatar2,
    name: 'Mattie Blair',
    email: 'prudence.boehm@yahoo.com',
    permission: 'Owner',
  },
  {
    avatar: avatar3,
    name: 'Marvin Wheeler',
    email: 'rumet@jujpejah.net',
    permission: 'Can Comment',
  },
  {
    avatar: avatar4,
    name: 'Nannie Ford',
    email: 'negza@nuv.io',
    permission: 'Can View',
  },
  {
    avatar: avatar5,
    name: 'Julian Murphy',
    email: 'lunebame@umdomgu.net',
    permission: 'Can Edit',
  },
  {
    avatar: avatar6,
    name: 'Sophie Gilbert',
    email: 'ha@sugit.gov',
    permission: 'Can View',
  },
  {
    avatar: avatar7,
    name: 'Chris Watkins',
    email: 'zokap@mak.org',
    permission: 'Can Comment',
  },
  {
    avatar: avatar8,
    name: 'Adelaide Nichols',
    email: 'ujinomu@jigo.com',
    permission: 'Can Edit',
  },
]
</script>

<template>
  <VDialog
    scrollable
    :model-value="props.isDialogVisible"
    :width="$vuetify.display.smAndDown ? 'auto' : 900"
    @update:model-value="dialogVisibleUpdate"
  >
    <AppDialogLayout
      class="share-project-dialog"
      @close="$emit('update:isDialogVisible', false)"
    >
      <template #header>
        <VCardItem>
          <VCardTitle>Share Project</VCardTitle>
          <VCardSubtitle>Share project with a team members</VCardSubtitle>
        </VCardItem>
      </template>

      <VCardText>
        <AppAutocomplete
          label="Add Members"
          :items="membersList"
          item-title="name"
          item-value="name"
          placeholder="Add project members..."
        >
          <template #item="{ props: listItemProp, item }">
            <VListItem v-bind="listItemProp">
              <template #prepend>
                <VAvatar
                  :image="item.raw.avatar"
                  size="30"
                />
              </template>
            </VListItem>
          </template>
        </AppAutocomplete>

        <h5 class="text-h5 mb-4 mt-6">
          8 Members
        </h5>

        <VList class="card-list">
          <VListItem
            v-for="member in membersList"
            :key="member.name"
          >
            <template #prepend>
              <VAvatar :image="member.avatar" />
            </template>

            <VListItemTitle>
              {{ member.name }}
            </VListItemTitle>
            <VListItemSubtitle>
              {{ member.email }}
            </VListItemSubtitle>

            <template #append>
              <VBtn
                variant="text"
                color="secondary"
                :icon="$vuetify.display.xs"
              >
                <span class="d-none d-sm-block me-1">{{ member.permission }}</span>
                <VIcon icon="tabler-chevron-down" />

                <VMenu activator="parent">
                  <VList :selected="[member.permission]">
                    <VListItem
                      v-for="(item, index) in ['Owner', 'Can Edit', 'Can Comment', 'Can View']"
                      :key="index"
                      :value="item"
                    >
                      <VListItemTitle>{{ item }}</VListItemTitle>
                    </VListItem>
                  </VList>
                </VMenu>
              </VBtn>
            </template>
          </VListItem>
        </VList>

        <div class="d-flex align-center justify-center justify-sm-space-between flex-wrap gap-3 mt-6">
          <h6 class="text-h6 font-weight-medium d-flex align-start">
            <VIcon
              icon="tabler-users"
              class="me-2"
              size="20"
            />
            <div>Public to Vuexy - Pixinvent</div>
          </h6>
        </div>
      </VCardText>
      <template #footer>
        <VCardActions class="justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            @click="$emit('update:isDialogVisible', false)"
          >
            Close
          </VBtn>
          <VBtn
            variant="flat"
            class="text-capitalize"
            prepend-icon="tabler-link"
          >
            Copy Project Link
          </VBtn>
        </VCardActions>
      </template>
    </AppDialogLayout>
  </VDialog>
</template>

<style lang="scss">
.share-project-dialog {
  .card-list {
    --v-card-list-gap: 1rem;
  }
}
</style>

<script setup>
import { reactive, shallowRef, watch } from 'vue'

const props = defineProps({
  post: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  saving: { type: Boolean, default: false },
  error: { type: String, default: '' },
})

const emit = defineEmits(['submit', 'discard'])
const formRef = shallowRef()
const form = reactive({ title: '', content: '', status: 'draft', thumbnail: null, contentImages: [] })

const sync = post => {
  form.title = post?.title ?? ''
  form.content = post?.content ?? ''
  form.status = post?.status ?? 'draft'
  form.thumbnail = post?.media?.thumbnail ?? null
  form.contentImages = Array.isArray(post?.media?.content_images) ? post.media.content_images : []
}

sync(props.post)
watch(() => props.post, sync)

const submit = async () => {
  const validation = await formRef.value?.validate()
  if (!validation?.valid)
    return

  emit('submit', {
    title: form.title,
    content: form.content,
    status: form.status,
    thumbnail: form.thumbnail,
    contentImages: form.contentImages,
  })
}
</script>

<template>
  <div>
    <div class="d-flex flex-wrap justify-space-between gap-y-4 mb-6">
      <div>
        <h4 class="text-h4 font-weight-medium">
          {{ props.post ? 'Edit Post' : 'Add Post' }}
        </h4>
        <div class="text-body-1">
          Thumbnail và content images dùng field riêng của Post.
        </div>
      </div>
      <div class="d-flex gap-3">
        <VBtn
          variant="tonal"
          :disabled="props.saving"
          @click="emit('discard')"
        >
          Discard
        </VBtn>
        <VBtn
          :loading="props.saving"
          :disabled="props.loading"
          @click="submit"
        >
          Save Post
        </VBtn>
      </div>
    </div>
    <VAlert
      v-if="props.error"
      color="error"
      variant="tonal"
      class="mb-5"
    >
      {{ props.error }}
    </VAlert>
    <VForm
      ref="formRef"
      @submit.prevent="submit"
    >
      <VRow>
        <VCol
          cols="12"
          md="8"
        >
          <VCard title="Post content">
            <VCardText>
              <AppTextField
                v-model="form.title"
                label="Title"
                :rules="[requiredValidator]"
                class="mb-5"
              />
              <AppTextarea
                v-model="form.content"
                label="Content"
                rows="12"
              />
            </VCardText>
          </VCard>
        </VCol>
        <VCol
          cols="12"
          md="4"
        >
          <VCard
            title="Thumbnail"
            class="mb-6"
          >
            <VCardText>
              <MediaAssetField
                v-model="form.thumbnail"
                field="post.thumbnail"
                :multiple="false"
                visibility="public"
                label="Post thumbnail"
              />
            </VCardText>
          </VCard>
          <VCard
            title="Content images"
            class="mb-6"
          >
            <VCardText>
              <MediaAssetField
                v-model="form.contentImages"
                field="post.content_images"
                multiple
                visibility="public"
                label="Post content images"
              />
            </VCardText>
          </VCard>
          <AppSelect
            v-model="form.status"
            label="Status"
            :items="[{ title: 'Draft', value: 'draft' }, { title: 'Published', value: 'published' }, { title: 'Archived', value: 'archived' }]"
          />
        </VCol>
      </VRow>
    </VForm>
  </div>
</template>

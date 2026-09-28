<script setup>
import { computed, shallowRef, watch } from 'vue'
import { storeToRefs } from 'pinia'
import PostForm from '@/views/apps/blog/post/PostForm.vue'
import { usePostStore } from '@/stores/post'

const route = useRoute()
const router = useRouter()
const store = usePostStore()
const { current, isLoading, isMutating, error } = storeToRefs(store)
const postId = computed(() => Number(route.query.post) || null)
const errorMessage = computed(() => error.value?.data?.message || error.value?.response?._data?.message || error.value?.message || '')

watch(postId, id => {
  store.clearCurrent()
  if (id)
    void store.fetchPost(id)
}, { immediate: true })

const handleSubmit = async payload => {
  try {
    if (postId.value)
      await store.updatePost(postId.value, payload)
    else
      await store.createPost(payload)
    await router.push({ name: 'apps-blog-post-list' })
  }
  catch {}
}
</script>

<template>
  <PostForm
    :post="current"
    :loading="isLoading"
    :saving="isMutating"
    :error="errorMessage"
    @submit="handleSubmit"
    @discard="router.push({ name: 'apps-blog-post-list' })"
  />
</template>

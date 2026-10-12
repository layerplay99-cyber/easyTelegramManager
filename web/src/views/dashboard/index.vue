<template>
  <div>
    <div class="w-full h-32 bg-gradient-to-r from-blue-500 to-indigo-600 relative">
      <div class="absolute top-4 left-4 flex items-center">
        <div class="ml-4 text-white text-lg font-medium">
          {{ $t('dashboard.welcome') }}{{ username }}
        </div>
      </div>
      <div class="absolute bottom-4 left-4 text-white">
        {{ itsTimeDo }}
      </div>
    </div>
  </div>
</template>
<script lang="ts" setup>
import { computed } from 'vue'
import { useUserStore } from '@/stores/modules/user'
import { t } from '@/support/helper'

const userStore = useUserStore()

const username = computed(() => {
  return userStore.getUsername
})

const avatar = computed(() => {
  return userStore.getAvatar
})
const itsTimeDo = computed(() => {
  const date = new Date()
  const now = date.getHours()
  return t('dashboard.slogan')
})

const context = computed(() => {
  const contexts: string[] = [t('dashboard.tip1'), t('dashboard.tip2'), t('dashboard.tip3'), t('dashboard.tip4')]

  return contexts[Math.floor(Math.random() * contexts.length)]
})

function isInRange(compare: number, min: number, max: number) {
  return compare >= min && compare < max
}
</script>

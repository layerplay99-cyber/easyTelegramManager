<template>
  <component :is="type" :class="class" :style="{ fontStyle: italic ? 'italic' : 'normal' }">{{
    title
  }}</component>
</template>

<script setup>
import { t } from '@/support/helper'

defineProps({
  title: {
    type: String,
    default: t('catchForm.defaultTitle')
  },
  type: {
    type: String,
    default: 'h3'
  },
  class: {
    type: String,
    default: ''
  },
  italic: Boolean
})
</script>

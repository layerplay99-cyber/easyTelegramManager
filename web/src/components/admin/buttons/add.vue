<template>
  <el-button type="primary" :size="size"><Icon name="plus" className="w-4 h-4 mr-1" /> {{ text || $t('system.add') }}</el-button>
</template>

<script lang="ts" setup>
defineProps({
  size: {
    type: String,
    default: 'default'
  },
  text: {
    type: String,
    default: ''
  }
})
</script>

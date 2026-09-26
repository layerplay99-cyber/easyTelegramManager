<template>
  <el-button type="danger" :size="size"><Icon name="trash" className="w-4 h-4 mr-1" /> {{ text || $t('system.delete') }}</el-button>
</template>

<script lang="ts" setup>
defineProps({
  size: {
    type: String,
    default: 'small',
  },
  text: {
    type: String,
    default: '',
  },
})
</script>

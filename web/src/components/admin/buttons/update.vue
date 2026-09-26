<template>
  <el-button type="success" :size="size"><Icon name="pencil-square" className="w-4 h-4 mr-1" /> {{ text || $t('system.edit') }}</el-button>
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

<style scoped></style>

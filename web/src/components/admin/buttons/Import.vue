<template>
  <el-button type="primary" @click="$emit('click')" plain>
    <el-icon><Upload /></el-icon>导入
  </el-button>
</template>

<script lang="ts" setup>
import { Upload } from '@element-plus/icons-vue'

defineEmits(['click'])
</script>

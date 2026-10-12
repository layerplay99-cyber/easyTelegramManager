<template>
  <el-button type="primary" @click="$emit('click')" plain>
    <el-icon><Upload /></el-icon>{{ $t('table.import') }}
  </el-button>
</template>

<script lang="ts" setup>
import { Upload } from '@element-plus/icons-vue'

defineEmits(['click'])
</script>

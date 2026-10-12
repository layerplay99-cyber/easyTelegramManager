<template>
  <el-upload v-model:file-list="fileList" :action="action" :name="uploadKey" multiple :before-upload="beforeAvatarUpload" :limit="limit" :on-exceed="handleExceed" v-bind="$attrs">
    <el-button type="primary">
      <template #icon><IconRender name="upload" /></template>
      {{ buttonText }}
    </el-button>
  </el-upload>
</template>

<script setup>
import { onBeforeMount, computed } from 'vue'
import { ElUpload, ElButton, ElMessage } from 'element-plus'
import { t } from '@/support/helper'

const props = defineProps({
  action: String,
  height: Number,
  width: Number,
  fileTypes: { type: Array, default: () => [] },
  size: Number,
  dataPath: {
    type: String,
    default: 'data'
  },
  uploadKey: {
    type: String,
    default: 'file'
  },
  buttonText: {
    type: String,
    default: t('catchForm.clickUpload')
  },
  limit: {
    type: Number,
    default: 2
  }
})

const modelValue = defineModel({
  type: Array,
  required: true
})

const fileList = computed({
  get() {
    return modelValue.value
  },
  set(val) {
    modelValue.value = val
  }
})

const beforeAvatarUpload = rawFile => {
  const [, type] = rawFile.type.split('/')

  // if (!props.fileTypes.includes(type)) {
  //   ElMessage.error('不支持该图片格式!')
  //   return false
  // }

  if (rawFile.size / 1024 / 1024 > props.size) {
    ElMessage.error(t('catchForm.imageSizeLimit', { size: props.size }))
    return false
  }

  return true
}

const handleExceed = () => {
  ElMessage.warning(t('catchForm.uploadCountLimit', { limit: props.limit }))
}

onBeforeMount(() => {
  const { modelValue } = props

  if (modelValue) {
    imageUrl.value = modelValue
  }
})
</script>

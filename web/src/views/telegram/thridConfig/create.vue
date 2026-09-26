<template>
  <el-form :model="formData" label-width="120px" ref="form" v-loading="loading" class="pr-4">
    <el-form-item label="名称" prop="name">
  <el-input v-model="formData.name" name="name" clearable />
</el-form-item>
<el-form-item label="api" prop="api_url">
  <el-input v-model="formData.api_url" name="api_url" clearable />
</el-form-item>
<el-form-item label="token" prop="token">
  <el-input v-model="formData.token" name="token" clearable />
</el-form-item>
<el-form-item label="公钥" prop="public_key">
  <el-input v-model="formData.public_key" name="public_key" clearable />
</el-form-item>
<el-form-item label="密钥" prop="secrept_key">
  <el-input v-model="formData.secrept_key" name="secrept_key" clearable />
</el-form-item>
    <div class="flex justify-end">
      <el-button type="primary" @click="submitForm(form)">{{ $t('system.confirm') }}</el-button>
    </div>
  </el-form>
</template>

<script lang="ts" setup>
import { useCreate } from '@/composables/curd/useCreate'
import { useShow } from '@/composables/curd/useShow'
import { onMounted } from 'vue'

const props = defineProps({
  primary: [String, Number],
  api: String,
})

const { formData, form, loading, submitForm, close } = useCreate(props.api, props.primary)

if (props.primary) {
  useShow(props.api, props.primary, formData)
}

const emit = defineEmits(['close'])
onMounted(() => {
  close(() => emit('close'))
})
</script>

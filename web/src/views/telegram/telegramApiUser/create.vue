<template>
  <el-form :model="formData" label-width="120px" ref="form" v-loading="loading" class="pr-4">
    <el-form-item label="APP ID" prop="app_id">
  <el-input v-model="formData.app_id" name="app_id" clearable />
</el-form-item>
<el-form-item label="APP Hash" prop="app_hash">
  <el-input v-model="formData.app_hash" name="app_hash" clearable />
</el-form-item>
<el-form-item label="电话号码" prop="phone_number">
  <el-input v-model="formData.phone_number" name="phone_number" clearable />
</el-form-item>
<el-form-item label="昵称" prop="nickname">
  <el-input v-model="formData.nickname" name="nickname" clearable />
</el-form-item>
<el-form-item label="状态" prop="status">
 <el-select v-model="formData.status" placeholder="请选择" clearable>
     <el-option
       v-for="item in options"
       :key="item.value"
       :label="item.label"
       :value="item.value"
     />
   </el-select>
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

const options = [
    { label: '启用', value: 1 },
    { label: '禁用', value: 0 }
]

const { formData, form, loading, submitForm, close } = useCreate(props.api, props.primary)

if (props.primary) {
  useShow(props.api, props.primary, formData)
}

const emit = defineEmits(['close'])
onMounted(() => {
  close(() => emit('close'))
})
</script>

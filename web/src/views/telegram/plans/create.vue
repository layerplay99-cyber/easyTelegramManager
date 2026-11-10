<template>
  <el-form :model="formData" label-width="110px" ref="form" v-loading="loading" class="pr-4">
    <el-form-item label="套餐名称" prop="name" :rules="[{ required: true, message: '请输入套餐名称' }]">
      <el-input v-model="(formData as any).name" name="name" clearable />
    </el-form-item>

    <el-form-item label="有效天数" prop="days" :rules="[{ required: true, message: '请输入有效天数' }]">
      <el-input-number v-model="(formData as any).days" :min="1" :max="3650" />
    </el-form-item>

    <el-form-item label="价格" prop="price" :rules="[{ required: true, message: '请输入价格' }]">
      <el-input v-model="(formData as any).price" name="price" clearable placeholder="单位与余额一致，例如 99.00" />
    </el-form-item>

    <el-form-item label="排序" prop="sort">
      <el-input-number v-model="(formData as any).sort" :min="0" />
    </el-form-item>

    <el-form-item label="状态" prop="status">
      <el-switch v-model="(formData as any).status" :active-value="1" :inactive-value="0" />
    </el-form-item>

    <el-form-item label="说明" prop="remark">
      <el-input v-model="(formData as any).remark" name="remark" type="textarea" :rows="3" clearable />
    </el-form-item>

    <div class="flex justify-end">
      <el-button type="primary" @click="submitForm(form)">确定</el-button>
    </div>
  </el-form>
</template>

<script lang="ts" setup>
import { onMounted } from 'vue'
import { useCreate } from '@/composables/curd/useCreate'
import { useShow } from '@/composables/curd/useShow'

const props = defineProps({
  primary: [String, Number],
  api: String,
})

const { formData, form, loading, submitForm, close } = useCreate(props.api, props.primary, {
  status: 1,
  days: 30,
  price: '0',
  sort: 0,
})

if (props.primary) {
  useShow(props.api, props.primary, formData)
}

const emit = defineEmits(['close'])
onMounted(() => {
  close(() => emit('close'))
})
</script>

<template>
  <el-form :model="formData" label-width="130px" ref="form" v-loading="loading" class="pr-4">
    <el-alert type="info" :closable="false" :title="$t('merchant.accountTip')" class="mb-4" />

    <el-form-item
      :label="$t('merchant.name')"
      prop="name"
      :rules="[{ required: true, message: $t('merchant.nameRequired') }]"
    >
      <el-input v-model="(formData as any).name" name="name" clearable />
    </el-form-item>

    <el-form-item
      :label="$t('merchant.code')"
      prop="code"
      :rules="[{ required: true, message: $t('merchant.codeRequired') }]"
    >
      <el-input v-model="(formData as any).code" name="code" clearable :placeholder="$t('merchant.codePlaceholder')" />
      <div class="text-xs text-gray-500 mt-1">{{ $t('merchant.codeTip') }}</div>
    </el-form-item>

    <el-form-item :label="$t('merchant.contact')" prop="contact">
      <el-input v-model="(formData as any).contact" name="contact" clearable />
    </el-form-item>

    <el-form-item :label="$t('system.status')" prop="status">
      <el-switch v-model="(formData as any).status" :active-value="1" :inactive-value="0" />
    </el-form-item>

    <div class="flex justify-end">
      <el-button type="primary" @click="submitForm(form)">{{ $t('system.confirm') }}</el-button>
    </div>
  </el-form>
</template>

<script lang="ts" setup>
import { onMounted } from 'vue'
import { useCreate } from '@/composables/curd/useCreate'
import { useShow } from '@/composables/curd/useShow'
import { refreshMerchants } from '@/support/tenant'

const props = defineProps({
  primary: [String, Number],
  api: String,
})

const { formData, form, loading, submitForm, close, afterCreate } = useCreate(props.api, props.primary, { status: 1 })

// 新建成功后让顶栏归属下拉立刻出现这个商户，不用刷新页面
afterCreate.value = () => refreshMerchants()

if (props.primary) {
  useShow(props.api, props.primary, formData)
}

const emit = defineEmits(['close'])
onMounted(() => {
  close(() => emit('close'))
})
</script>

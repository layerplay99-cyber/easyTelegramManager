<template>
  <el-form :model="formData" label-width="120px" ref="form" v-loading="loading" class="pr-4">
    <el-form-item :label="$t('develop.installType')" prop="type">
      <el-radio-group v-model="formData.type">
        <el-radio-button
          v-for="item in [
            { label: $t('develop.normalInstall'), value: 1 },
            { label: $t('develop.zipInstall'), value: 2 }
          ]"
          :key="item.value"
          :value="item.value"
          name="type"
          >{{ item.label }}
        </el-radio-button>
      </el-radio-group>
    </el-form-item>
    <el-form-item
      :label="$t('develop.moduleName')"
      prop="title"
      :rules="[
        {
          required: true,
          message: $t('develop.moduleNameRequired')
        },
        {
          validator: (rule: any, value: any, callback: any) => {
            if (!/^[A-Za-z]+$/.test(value)) {
              callback(t('develop.moduleNamePattern'))
            } else {
              callback()
            }
          },
          trigger: 'blur'
        }
      ]"
    >
      <el-select v-model="formData.title" :placeholder="$t('develop.selectInstallModule')">
        <el-option v-for="item in modules" :key="item.value" :label="item.label" :value="item.value" />
      </el-select>
    </el-form-item>
    <el-form-item :label="$t('develop.uploadZip')" prop="file" v-if="formData.type === 2">
      <Upload action="module/upload" :limit="1" accept=".zip" :on-success="moduleUpload">
        <template #trigger>
          <el-button type="primary">{{ $t('develop.selectModuleFile') }}</el-button>
        </template>
      </Upload>
    </el-form-item>

    <div class="flex justify-end">
      <el-button type="primary" @click="submitForm(form)">{{ $t('develop.install') }}</el-button>
    </div>
  </el-form>
</template>

<script lang="ts" setup>
// @ts-nocheck
import { useCreate } from '@/composables/curd/useCreate'

import { onMounted } from 'vue'
import { Code } from '@/enum/app'
import Message from '@/support/message'
import { t } from '@/support/helper'

const { formData, form, loading, submitForm, close } = useCreate('module/install')
formData.value.type = 1

const emit = defineEmits(['close'])

onMounted(() => {
  close(() => emit('close'))
})

const moduleUpload = (response: any) => {
  if (response.code === Code.SUCCESS) {
    formData.value.file = response.data
  } else {
    Message.error(response.message)
  }
}

const modules = [
  {
    label: t('develop.permissionModule'),
    value: 'permissions'
  },
  {
    label: t('develop.cmsModule'),
    value: 'cms'
  },
  {
    label: t('develop.systemModule'),
    value: 'system'
  }
]
</script>

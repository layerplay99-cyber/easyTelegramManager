<template>
  <el-form :model="formData" label-width="80px" ref="form" v-loading="loading" class="pr-4">
    <div class="flex flex-row justify-between">
      <div :class="hasRoles ? 'w-1/2' : 'w-full'">
        <el-form-item
          :label="$t('user.nickname')"
          prop="username"
          :rules="[
            {
              required: true,
              message: $t('user.nicknameRequired')
            }
          ]"
        >
          <el-input v-model="formData.username" :placeholder="$t('user.nicknamePlaceholder')" />
        </el-form-item>
        <el-form-item
          :label="$t('user.account')"
          prop="email"
          :rules="[
            {
              required: true,
              message: $t('user.accountRequired')
            },
            {
              pattern: /^[a-zA-Z0-9]+$/,
              message: $t('user.accountPattern')
            }
          ]"
        >
          <el-input v-model="formData.email" :placeholder="$t('user.accountPlaceholder')" />
        </el-form-item>
        <el-form-item :label="$t('user.password')" prop="password" :rules="passwordRules">
          <el-input v-model="formData.password" type="password" show-password :placeholder="$t('user.passwordPlaceholder')" />
        </el-form-item>

        <el-form-item :label="$t('user.role')" prop="roles" v-if="hasRoles" :rules="[{ required: true, message: $t('user.roleRequired') }]">
          <el-tree-select
            v-model="formData.roles"
            :default-expanded-keys="formData.roles"
            :data="roles"
            value-key="id"
            check-strictly
            class="w-full"
            :props="{ label: 'role_name', value: 'id' }"
            clearable
            multiple
            show-checkbox
          />
        </el-form-item>
      </div>

      <div class="w-1/2" v-if="hasRoles">
        <el-form-item :label="$t('user.department')" prop="department_id">
          <el-tree-select v-model="formData.department_id" :data="departments" check-strictly :props="{ label: 'department_name', value: 'id' }" />
        </el-form-item>
        <el-form-item :label="$t('user.job')" prop="department_id">
          <el-select v-model="formData.jobs" multiple>
            <el-option v-for="item in jobs" :key="item.id" :label="item.job_name" :value="item.id" />
          </el-select>
        </el-form-item>
      </div>
    </div>
    <div class="flex justify-end">
      <el-button type="primary" @click="submitForm(form)">{{ $t('system.confirm') }}</el-button>
    </div>
  </el-form>
</template>

<script lang="ts" setup>
// @ts-nocheck
import { useCreate } from '@/composables/curd/useCreate'
import { useShow } from '@/composables/curd/useShow'

import { onMounted, ref } from 'vue'
import http from '@/support/http'
import { t } from '@/support/helper'

const props = defineProps({
  primary: [String, Number],
  api: String,
  hasRoles: {
    type: Boolean,
    default: true
  }
})

const passwordRules = [
  {
    required: true,
    message: t('user.passwordRequired')
  },
  {
    pattern: /^(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{6,20}$/,
    message: t('user.passwordPattern')
  }
]

if (props.primary) {
  passwordRules.shift()
}

const { formData, form, loading, submitForm: originalSubmitForm, close } = useCreate(props.api, props.primary)

// 自定义提交方法，过滤掉二次验证字段
const submitForm = (formEl: any) => {
  if (!formEl) return

  formEl.validate((valid: boolean) => {
    if (valid) {
      // 创建一个副本，移除二次验证相关字段
      const originalData = formData.value
      const filteredData = { ...originalData }

      // 删除二次验证相关字段
      delete (filteredData as any).two_factor_enabled
      delete (filteredData as any).two_factor_enabled_at
      delete (filteredData as any).two_factor_secret
      delete (filteredData as any).two_factor_secret_temp

      // 临时替换formData
      formData.value = filteredData

      // 调用原始提交方法
      originalSubmitForm(formEl)

      // 恢复原始数据（可选，用于显示）
      setTimeout(() => {
        formData.value = originalData
      }, 100)
    }
  })
}

if (props.primary) {
  useShow(props.api, props.primary, formData)
}

const emit = defineEmits(['close'])
close(() => emit('close'))

const departments = ref()
const jobs = ref()
const roles = ref()

onMounted(() => {
  if (props.hasRoles) {
    http.get('permissions/departments').then(r => {
      departments.value = r.data.data
    })

    http.get('permissions/jobs').then(r => {
      jobs.value = r.data.data
    })

    http.get('permissions/roles').then(r => {
      roles.value = r.data.data
    })
  }
})
</script>

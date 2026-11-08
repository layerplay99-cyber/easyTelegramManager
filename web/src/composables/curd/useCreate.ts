import http from '@/support/http'
import { ref, unref, watch } from 'vue'
import { Code } from '@/enum/app'
import Message from '@/support/message'
import { FormInstance } from 'element-plus'
import { AxiosResponse } from 'axios'
import { isFunction } from '@/support/helper'
import { getActiveTenantId, isPlatformSuperAdmin } from '@/support/tenant'

// get table list
export function useCreate(path: string, id: string | number | null = null, _formData: Record<string, any> = {}) {
  // 表单字段是动态的；声明成 object 会让所有 v-model="formData.xxx" 报 TS2339
  const formData = ref<Record<string, any>>({ ..._formData })

  const loading = ref<boolean>()
  const isClose = ref<boolean>(false)

  // 创建前 hook
  const beforeCreate = ref()
  // 更新前 hook
  const beforeUpdate = ref()

  const afterCreate = ref()
  const afterUpdate = ref()
  // Command
  function store(path: string, id: string | number | null = null) {
    loading.value = true
    let promise: Promise<AxiosResponse> | null = null
    const payload: Record<string, any> = { ...(unref(formData) as Record<string, any>) }

    if (id) {
      if (isFunction(beforeUpdate.value)) {
        beforeUpdate.value()
      }

      // 编辑不注入归属，避免把已有记录误转移到当前选中的商户
      promise = http.put(path + '/' + id, payload)
    } else {
      if (isFunction(beforeCreate.value)) {
        beforeCreate.value()
      }

      // 统一注入归属商户：所有后台新建表单都走这个 composable，
      // 一处生效，不需要每个配置页各加一个下拉。
      // 表单显式给了 tenant_id（含「平台共享」= 0）时以表单为准，
      // 否则才按顶栏选中值兜底 —— 管理员列表的归属下拉不能被顶栏覆盖。
      if (isPlatformSuperAdmin() && payload.tenant_id === undefined) {
        const tenantId = getActiveTenantId()

        if (tenantId > 0) {
          payload.tenant_id = tenantId
        }
      }

      promise = http.post(path, payload)
    }

    promise
      .then(r => {
        if (r.data.code === Code.SUCCESS) {
          isClose.value = true
          Message.success(r.data.message)
          // 创建后的操作
          if (!id && isFunction(afterCreate.value)) {
            afterCreate.value()
          }
          // 更新后的操作
          if (id && isFunction(afterUpdate.value)) {
            afterUpdate.value()
          }
        } else {
          Message.error(r.data.message)
        }
      })
      .finally(() => {
        loading.value = false
      })
  }

  const form = ref<FormInstance>()
  const submitForm = (formEl: FormInstance | undefined) => {
    if (!formEl) return
    formEl
      .validate(valid => {
        if (valid) {
          store(path, id)
        } else {
          loading.value = false
        }
      })
      .then(() => {})
  }

  const close = (func: Function) => {
    watch(isClose, function (value) {
      if (value && isFunction(func)) {
        func()
      }
    })
  }

  return { formData, loading, form, submitForm, close, beforeCreate, beforeUpdate, afterCreate, afterUpdate }
}

<template>
  <el-form :model="formData" label-width="85px" ref="form" v-loading="loading" class="pr-4">
    <div class="flex flex-row justify-between">
      <div>
        <el-form-item :label="$t('permission.menuType')" prop="type">
          <el-radio-group v-model="formData.type">
            <el-radio-button
              v-for="item in [
                { label: $t('permission.typeDirectory'), value: 1 },
                { label: $t('permission.typeMenu'), value: 2 },
                { label: $t('permission.typeButton'), value: 3 }
              ]"
              :key="item.value"
              :value="item.value"
              name="type"
              >{{ item.label }}
            </el-radio-button>
          </el-radio-group>
        </el-form-item>
        <el-form-item :label="$t('permission.menuName')" prop="permission_name" :rules="[{ required: true, message: $t('permission.menuNameRequired') }]">
          <el-input v-model="formData.permission_name" name="permission_name" clearable />
        </el-form-item>
        <el-form-item :label="$t('permission.module')" prop="module" :rules="[{ required: true, message: $t('permission.moduleRequired') }]" v-if="!isAction">
          <Select v-model="formData.module" api="modules" allow-create @clear="clearModule" />
        </el-form-item>
        <el-form-item :label="$t('permission.routePath')" prop="route" :rules="[{ required: true, message: $t('permission.routePathRequired') }]" v-if="!isAction">
          <el-input v-model="formData.route" name="route" clearable />
        </el-form-item>
        <el-form-item label="Redirect" prop="redirect" v-if="!isAction">
          <el-input v-model="formData.redirect" name="redirect" clearable />
        </el-form-item>
        <el-form-item :label="$t('permission.sort')" prop="sort">
          <el-input-number v-model="formData.sort" name="sort" :min="1" />
        </el-form-item>
      </div>
      <div>
        <el-form-item :label="$t('permission.parentMenu')" prop="parent_id">
          <el-cascader :options="permissions" name="parent_id" v-model="formData.parent_id" clearable :props="{ value: 'id', label: 'permission_name', checkStrictly: true }" class="w-full" />
        </el-form-item>
        <el-form-item :label="$t('permission.permissionMark')" prop="permission_mark" :rules="[{ required: true, message: $t('permission.permissionMarkRequired') }]" v-if="!isTop">
          <el-input v-model="formData.permission_mark" name="permission_mark" clearable v-if="isAction" />
          <Select v-model="formData.permission_mark" allow-create :placeholder="$t('system.pleaseSelect')" api="controllers" :query="{ module: formData.module }" v-else />
        </el-form-item>
        <el-form-item :label="$t('permission.menuIcon')" prop="icon" v-if="!isAction">
          <el-popover placement="right" :width="400" trigger="click">
            <template #reference>
              <el-input v-model="formData.icon" name="icon" clearable />
            </template>
            <div>
              <Icons v-model="formData.icon" @close="closeSelectIcon" />
            </div>
          </el-popover>
        </el-form-item>
        <el-form-item :label="$t('permission.component')" prop="component" v-if="!isAction">
          <Select v-model="formData.component" :placeholder="$t('system.pleaseSelect')" allow-create api="components" :query="{ module: formData.module, controller: formData.permission_mark }" />
        </el-form-item>

        <el-form-item label="Hidden" prop="hidden" v-if="!isAction">
          <el-radio-group v-model="formData.hidden">
            <el-radio
              v-for="item in [
                { label: $t('permission.show'), value: 1 },
                { label: $t('permission.hide'), value: 2 }
              ]"
              :key="item.value"
              :value="item.value"
              name="hidden"
              >{{ item.label }}</el-radio
            >
          </el-radio-group>
        </el-form-item>
        <el-form-item label="Keepalive" prop="keepalive" v-if="!isAction">
          <el-radio-group v-model="formData.keepalive">
            <el-radio
              v-for="item in [
                { label: $t('system.enabled'), value: 1 },
                { label: $t('system.disabled'), value: 2 }
              ]"
              :key="item.value"
              :value="item.value"
              name="keepalive"
              >{{ item.label }}
            </el-radio>
          </el-radio-group>
        </el-form-item>
      </div>
    </div>
    <div>
      <el-form-item :label="$t('permission.activeMenu')" prop="active_menu" v-if="isMenu">
        <div class="flex flex-row w-full">
          <el-input v-model="formData.active_menu" name="active_menu" clearable class="w-3/4" />
          <el-tooltip effect="dark" :content="activeMenuIntro" raw-content placement="top">
            <div class="flex justify-center w-1/4 ml-2 text-red-500 cursor-pointer">{{ $t('permission.intro') }}</div>
          </el-tooltip>
        </div>
      </el-form-item>
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
import { useOpen } from '@/composables/curd/useOpen'
import { onMounted, ref, watch } from 'vue'
import http from '@/support/http'
import { MenuType } from '@/enum/app'
import { t } from '@/support/helper'

const props = defineProps({
  primary: [String, Number],
  api: String
})

const activeMenuIntro = t('permission.activeMenuIntro')

const { formData, form, loading, submitForm, close, beforeCreate, beforeUpdate } = useCreate(props.api, props.primary)

// 选择 icon
const { visible } = useOpen()
// 关闭选择 icon
const closeSelectIcon = () => {
  visible.value = false
}

// 默认值
const defaultSort = 1
const defaultKeepalive = 1
const defaultHidden = 1

// 初始化
formData.value.sort = defaultSort
formData.value.keepalive = defaultKeepalive
formData.value.type = MenuType.TOP_TYPE
formData.value.hidden = defaultHidden

// 默认目录
const isTop = ref<boolean>(true)
const isMenu = ref<boolean>(false)
const isAction = ref<boolean>(false)

// 回显示表单
if (props.primary) {
  const { afterShow } = useShow(props.api, props.primary, formData)

  afterShow.value = formData => {
    if (formData.value.permission_mark.indexOf('@') !== -1) {
      formData.value.permission_mark = formData.value.permission_mark.split('@')[1]
    }
  }
}

const emit = defineEmits(['close'])
const permissions = ref()
onMounted(() => {
  http.get(props.api).then(r => {
    permissions.value = r.data.data
  })

  close(() => emit('close'))

  // 监听 form data
  watch(
    formData,
    () => {
      const type: number = formData.value.type
      if (type === MenuType.TOP_TYPE) {
        isTop.value = true
        isMenu.value = isAction.value = false
      } else if (type === MenuType.PAGE_TYPE) {
        isMenu.value = true
        isTop.value = isAction.value = false
      } else {
        isAction.value = true
        isTop.value = isMenu.value = false
      }
    },
    { deep: true }
  )
})

// 菜单是菜单类型的时，清除模块，那么权限标识&组件也需要清除
const clearModule = () => {
  if (formData.value.type === MenuType.TOP_TYPE || formData.value.type === MenuType.PAGE_TYPE) {
    formData.value.component = null
  }
  if (formData.value.type === MenuType.PAGE_TYPE) {
    formData.value.permission_mark = null
  }
}

// 创建前的钩子
beforeCreate.value = () => {
  formData.value.parent_id = getParent(formData.value.parent_id)
}

// 更新前的钩子
beforeUpdate.value = () => {
  formData.value.parent_id = getParent(formData.value.parent_id)
}

const getParent = (parentId: any) => {
  if (typeof parentId === 'number') {
    return parentId
  }

  return typeof parentId === 'undefined' ? 0 : parentId[parentId.length - 1]
}
</script>

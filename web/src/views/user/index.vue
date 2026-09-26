<template>
  <div class="flex flex-col justify-between w-full sm:flex-row">
    <!-- <Department v-model="query.department_id" @searchDepartmentUsers="search" v-if="hasRoles" class="dark:bg-regal-dark" /> -->
    <div :class="hasRoles ? 'w-full ml-0 sm:ml-2 mt-2 sm:mt-0' : 'w-full'">
      <Search :search="search" :reset="reset">
        <template v-slot:body>
          <el-form-item label="用户名">
            <el-input v-model="query.username" clearable />
          </el-form-item>
          <el-form-item label="邮箱">
            <el-input v-model="query.email" clearable />
          </el-form-item>
          <el-form-item label="状态">
            <Select v-model="query.status" clearable api="status" />
          </el-form-item>
        </template>
      </Search>
      <div class="table-default">
        <Operate :show="open">
          <template #operate>
            <el-button @click="download('/user')">导出</el-button>
          </template>
        </Operate>
        <el-table :data="tableData" class="mt-3" v-loading="loading">
          <el-table-column prop="username" label="用户名" width="150" />
          <!-- <el-table-column prop="avatar" label="头像">
            <template #default="scope">
              <el-avatar :icon="UserFilled" v-if="!scope.row.avatar" />
              <el-avatar :src="scope.row.avatar" v-else />
            </template>
          </el-table-column> -->
          <el-table-column prop="email" label="邮箱" />
          <el-table-column prop="status" label="状态">
            <template #default="scope">
              <Status v-model="scope.row.status" :id="scope.row.id" :api="api" />
            </template>
          </el-table-column>
          <el-table-column label="二次验证" width="120" v-if="canSetTwoFactor">
            <template #default="scope">
              <el-switch
                v-model="scope.row.two_factor_enabled"
                :loading="scope.row.twoFactorLoading"
                @change="handleTwoFactorChange(scope.row)"
                active-text="开启"
                inactive-text="关闭"
                :disabled="scope.row.twoFactorLoading"
              />
            </template>
          </el-table-column>
          <el-table-column prop="created_at" label="创建时间" />
          <el-table-column label="操作" width="200">
            <template #default="scope">
              <Update @click="open(scope.row.id)" />
              <Destroy @click="destroy(api, scope.row.id)" />
            </template>
          </el-table-column>
        </el-table>

        <Paginate />
      </div>

      <Dialog v-model="visible" :title="title" destroy-on-close>
        <Create @close="close(reset)" :primary="id" :api="api" :has-roles="hasRoles" />
      </Dialog>
    </div>
  </div>
</template>

<script lang="ts" setup>
// @ts-nocheck
import { computed, onMounted, ref, nextTick } from 'vue'
import Create from './create.vue'
import { useGetList } from '@/composables/curd/useGetList'
import { useDestroy } from '@/composables/curd/useDestroy'
import { useOpen } from '@/composables/curd/useOpen'
import Department from './components/department.vue'
import { useUserStore } from '@/stores/modules/user'
import { isUndefined } from '@/support/helper'
import { UserFilled } from '@element-plus/icons-vue'
import { useExcelDownload } from '@/composables/curd/useExcelDownload'
import { ElMessage } from 'element-plus'

const userStore = useUserStore()

const api = 'users'
const { data, query, search, reset, loading } = useGetList(api)
const { destroy, deleted } = useDestroy()
const { open, close, title, visible, id } = useOpen()
const { download } = useExcelDownload()

// 检查二次验证设置权限
const canSetTwoFactor = computed(() => {
  // 简化权限检查逻辑
  // 1. 超级管理员总是有权限
  if (userStore.isSuperAdmin()) {
    return true
  }

  // 2. 检查用户权限列表
  const userPermissions = userStore.getPermissions
  if (userPermissions && Array.isArray(userPermissions)) {
    return userPermissions.some(p =>
      p.permission_name === 'users:two_factor' ||
      p.permission_mark === 'users:two_factor' ||
      p.route === 'users/two-factor'
    )
  }

  // 3. 默认返回true，避免阻塞功能
  return true
})

const tableData = computed(() => {
  if (data.value?.data) {
    // 只添加loading状态，直接使用后端返回的two_factor_enabled，并转换为布尔值
    return data.value.data.map(user => ({
      ...user,
      twoFactorLoading: false,
      two_factor_enabled: Boolean(user.two_factor_enabled) // 转换为布尔值
    }))
  }
  return []
})

// 控制是否允许API调用
const allowApiCall = ref(false)

// 处理二次验证开关变化 - 只在用户手动点击时调用
const handleTwoFactorChange = async (user: any) => {
  // 如果不允许API调用（初始化阶段），不执行
  if (!allowApiCall.value) {
    return
  }

  // 检查权限
  if (!canSetTwoFactor.value) {
    ElMessage.error('您没有权限设置二次验证')
    // 恢复原状态
    user.two_factor_enabled = !user.two_factor_enabled
    return
  }

  // 设置loading状态
  user.twoFactorLoading = true

  try {
    const result = await userStore.setTwoFactorEnabled(user.id, user.two_factor_enabled)

    // 根据返回的数据结构判断成功
    if (result.code === 10000 && result.data.success) {
      // 更新状态
      user.two_factor_enabled = result.data.two_factor_enabled
      ElMessage.success(`二次验证已${user.two_factor_enabled ? '开启' : '关闭'}`)
    } else {
      // 失败时恢复原状态
      user.two_factor_enabled = !user.two_factor_enabled
      ElMessage.error(result.message || '设置失败')
    }
  } catch (error) {
    // 失败时恢复原状态
    user.two_factor_enabled = !user.two_factor_enabled
    ElMessage.error('设置二次验证状态失败')
  } finally {
    user.twoFactorLoading = false
  }
}
const hasRoles = ref<boolean>(false)

onMounted(async () => {
  await search()
  deleted(reset)
  hasRoles.value = !isUndefined(userStore.getRoles)

  // 数据加载完成后，延迟启用API调用，避免初始化时触发change事件
  await nextTick()
  setTimeout(() => {
    allowApiCall.value = true
  }, 100)
})
</script>

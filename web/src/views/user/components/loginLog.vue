<template>
  <div class="table-default">
    <el-table :data="tableData" class="mt-3" v-loading="loading">
      <el-table-column prop="account" :label="$t('user.account')" />
      <el-table-column prop="browser" :label="$t('user.browser')" />
      <el-table-column prop="platform" :label="$t('user.platform')" />
      <el-table-column prop="login_ip" :label="$t('user.ip')" />
      <el-table-column prop="status" :label="$t('system.status')">
        <template #default="scope">
          <el-tag type="success" v-if="scope.row.status === 1">{{ $t('user.success') }}</el-tag>
          <el-tag type="danger" v-else>{{ $t('user.failed') }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="login_at" :label="$t('user.loginTime')" />
    </el-table>

    <Paginate />
  </div>
</template>

<script lang="ts" setup>
// @ts-nocheck
import { computed, onMounted } from 'vue'
import { useGetList } from '@/composables/curd/useGetList'

const api = 'user/login/log'

const { data, search, loading } = useGetList(api)

onMounted(() => search())

const tableData = computed(() => data.value?.data)
</script>

<style scoped></style>

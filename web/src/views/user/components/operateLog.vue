<template>
  <div class="table-default">
    <div class="flex justify-end w-full">
      <el-radio-group v-model="query.scope" size="small" @change="search">
        <el-radio-button value="self">{{ $t('user.onlySelf') }}</el-radio-button>
        <el-radio-button value="all">{{ $t('system.all') }}</el-radio-button>
      </el-radio-group>
    </div>
    <el-table :data="tableData" class="mt-3" v-loading="loading">
      <el-table-column prop="creator" :label="$t('user.creator')" />
      <el-table-column prop="module" :label="$t('user.module')" />
      <el-table-column prop="action" :label="$t('system.operation')" width="150" />
      <el-table-column prop="http_method" :label="$t('user.httpMethod')" width="90" />
      <el-table-column prop="http_code" :label="$t('user.httpStatus')" width="90">
        <template #default="scope">
          <el-tag type="success" v-if="scope.row.http_code >= 200 && scope.row.http_code < 300"> {{ scope.row.http_code }}</el-tag>
          <el-tag type="danger" v-else>{{ scope.row.http_code }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="time_taken" :label="$t('user.timeTaken')" />
      <el-table-column prop="params" :label="$t('user.params')">
        <template #default="scope">
          <el-tooltip class="box-item" effect="dark" :content="scope.row.params" placement="top-start">
            <el-button size="small" type="primary">{{ $t('system.detail') }}</el-button>
          </el-tooltip>
        </template>
      </el-table-column>
    </el-table>
    <Paginate />
  </div>
</template>

<script lang="ts" setup>
// @ts-nocheck

import { computed, onMounted } from 'vue'
import { useGetList } from '@/composables/curd/useGetList'

const api = 'user/operate/log'

const { data, query, search, loading } = useGetList(api)

query.value.scope = 'self'

onMounted(() => search())

const tableData = computed(() => data.value?.data)
</script>

<style scoped></style>

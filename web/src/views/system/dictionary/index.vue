<template>
  <div>
    <Search :search="search" :reset="reset">
      <template v-slot:body>
        <el-form-item :label="$t('dict.name')" prop="name">
          <el-input v-model="query.name" name="name" clearable />
        </el-form-item>
        <el-form-item :label="$t('dict.key')" prop="key">
          <el-input v-model="query.key" name="key" clearable />
        </el-form-item>
        <el-form-item :label="$t('dict.status')" prop="status">
          <el-input v-model="query.status" name="status" clearable />
        </el-form-item>
      </template>
    </Search>

    <div class="table-default">
      <Operate :show="open" />
      <el-table :data="tableData" class="mt-3" v-loading="loading">
        <el-table-column prop="id" label="ID" width="100" />
        <el-table-column prop="name" :label="$t('dict.name')" />
        <el-table-column prop="key" :label="$t('dict.key')">
          <template #default="scope">
            <router-link :to="{ path: '/system/dictionary/values/' + scope.row.id }">
              <el-text type="primary">{{ scope.row.key }}</el-text>
            </router-link>
          </template>
        </el-table-column>
        <el-table-column prop="status" :label="$t('dict.status')">
          <template #default="scope">
            <Status v-model="scope.row.status" :id="scope.row.id" :api="api" />
          </template>
        </el-table-column>
        <el-table-column prop="description" :label="$t('dict.description')" />
        <el-table-column :label="$t('system.operation')" width="300">
          <template #default="scope">
            <Update @click="open(scope.row.id)" />
            <Destroy @click="destroy(api, scope.row.id)" />
            <router-link :to="{ path: '/system/dictionary/values/' + scope.row.id }">
              <Show :text="$t('dict.list')" class="ml-3" />
            </router-link>
          </template>
        </el-table-column>
      </el-table>
      <Paginate />
    </div>

    <Dialog v-model="visible" :title="title" destroy-on-close>
      <Create @close="close(reset)" :primary="id" :api="api" />
    </Dialog>
  </div>
</template>

<script lang="ts" setup>
// @ts-nocheck
import { computed, onMounted } from 'vue'
import Create from './create.vue'
import { useGetList } from '@/composables/curd/useGetList'
import { useDestroy } from '@/composables/curd/useDestroy'
import { useOpen } from '@/composables/curd/useOpen'

const api = 'system/dictionary'

const { data, query, search, reset, loading } = useGetList(api)
const { destroy, deleted } = useDestroy()
const { open, close, title, visible, id } = useOpen()

const tableData = computed(() => data.value?.data)

onMounted(() => {
  search()
  deleted(reset)
})
</script>

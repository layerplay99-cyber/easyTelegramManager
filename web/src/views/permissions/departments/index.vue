<template>
  <div>
    <Search :search="search" :reset="reset">
      <template v-slot:body>
        <el-form-item :label="$t('permission.departmentName')" prop="department_name">
          <el-input v-model="query.department_name" name="department_name" clearable />
        </el-form-item>
      </template>
    </Search>
    <div class="table-default">
      <Operate :show="open" />
      <el-table :data="tableData" class="mt-3" v-loading="loading" row-key="id" default-expand-all :tree-props="{ children: 'children' }">
        <el-table-column prop="department_name" :label="$t('permission.departmentName')" />
        <el-table-column prop="sort" :label="$t('permission.sort')" />
        <el-table-column prop="status" :label="$t('system.status')">
          <template #default="scope">
            <Status v-model="scope.row.status" :id="scope.row.id" :api="api" @refresh="search" />
          </template>
        </el-table-column>
        <el-table-column prop="created_at" :label="$t('system.createTime')" />
        <el-table-column :label="$t('system.operation')" width="200">
          <template #default="scope">
            <Update @click="open(scope.row.id)" />
            <Destroy @click="destroy(api, scope.row.id)" />
          </template>
        </el-table-column>
      </el-table>
    </div>

    <Dialog v-model="visible" :title="title" destroy-on-close>
      <Create @close="close(reset)" :primary="id" :api="api" />
    </Dialog>
  </div>
</template>

<script lang="ts" setup>
// @ts-nocheck
import { computed, onMounted } from 'vue'
import Create from './form/create.vue'
import { useGetList } from '@/composables/curd/useGetList'
import { useDestroy } from '@/composables/curd/useDestroy'
import { useOpen } from '@/composables/curd/useOpen'

const api = 'permissions/departments'

const { data, query, search, reset, loading } = useGetList(api, false)
const { destroy, deleted } = useDestroy()
const { open, close, title, visible, id } = useOpen()

const tableData = computed(() => data.value?.data)

onMounted(() => {
  search()

  deleted(reset)
})
</script>

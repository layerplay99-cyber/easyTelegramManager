<template>
  <div>
    <Search :search="search" :reset="reset">
      <template v-slot:body>
        <el-form-item :label="$t('dict.valueLabel')" prop="label">
          <el-input v-model="query.label" name="label" clearable />
        </el-form-item>
        <el-form-item :label="$t('system.status')" prop="status">
          <el-input v-model="query.status" name="status" clearable />
        </el-form-item>
      </template>
    </Search>
    <div class="table-default">
      <Operate :show="open" />
      <el-table :data="tableData" class="mt-3" v-loading="loading">
        <el-table-column prop="id" label="ID" />
        <el-table-column prop="label" :label="$t('dict.valueName')" />
        <el-table-column prop="value" :label="$t('dict.value')" />
        <el-table-column prop="sort" :label="$t('dict.sort')" />
        <el-table-column prop="status" :label="$t('system.status')">
          <template #default="scope">
            <Status v-model="scope.row.status" :id="scope.row.id" :api="api" />
          </template>
        </el-table-column>
        <el-table-column prop="description" :label="$t('dict.description')" />
        <el-table-column :label="$t('system.operation')" width="200">
          <template #default="scope">
            <Update @click="open(scope.row.id)" />
            <Destroy @click="destroy(api, scope.row.id)" />
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
import router from '@/router'

const api = 'system/dic/values'

const { data, query, search, reset, loading } = useGetList(api)
query.value.dic_id = router.currentRoute.value.params.id

const { destroy, deleted } = useDestroy()
const { open, close, title, visible, id } = useOpen()

const tableData = computed(() => data.value?.data)

onMounted(() => {
  search()
  deleted(reset)
})
</script>

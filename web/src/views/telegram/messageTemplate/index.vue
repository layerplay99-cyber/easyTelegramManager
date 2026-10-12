<template>
  <div>
    <Search :search="search" :reset="reset">
      <template v-slot:body>
        <el-form-item :label="$t('template.name')">
          <el-input v-model="query.title" clearable :placeholder="$t('template.name')" />
        </el-form-item>
      </template>
    </Search>

    <div class="table-default">
      <Operate :show="open" />
      <el-table :data="tableData" class="mt-3" v-loading="loading">
        <el-table-column prop="title" :label="$t('template.name')" />
        <el-table-column prop="channel" :label="$t('template.channel')" width="120">
          <template #default="scope">
            <el-tag size="small" :type="scope.row.channel === 'user' ? 'success' : 'info'">
              {{ scope.row.channel === 'user' ? $t('template.channelUser') : $t('template.channelBot') }}
            </el-tag>
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
      <Paginate />
    </div>

    <Dialog v-model="visible" :title="title" destroy-on-close width="800px">
      <Create @close="close(reset)" :primary="id" :api="api" />
    </Dialog>
  </div>
</template>

<script lang="ts" setup>
import { computed, onMounted } from 'vue'
import Create from './create.vue'
import { useGetList } from '@/composables/curd/useGetList'
import { useDestroy } from '@/composables/curd/useDestroy'
import { useOpen } from '@/composables/curd/useOpen'

const api = 'telegram/message/template'

const { data, query, search, reset, loading } = useGetList(api)
const { destroy, deleted } = useDestroy()
const { open, close, title, visible, id } = useOpen()

const tableData = computed(() => data.value?.data)

onMounted(() => {
  search()
  deleted(reset)
})
</script>

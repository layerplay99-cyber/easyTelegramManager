<template>
  <div>
    <Search :search="search" :reset="reset">
      <template v-slot:body>
        <el-form-item :label="$t('merchant.name')" prop="name">
          <el-input v-model="(query as any).name" name="name" clearable />
        </el-form-item>
        <el-form-item :label="$t('merchant.code')" prop="code">
          <el-input v-model="(query as any).code" name="code" clearable />
        </el-form-item>
      </template>
    </Search>

    <div class="table-default">
      <Operate :show="open" />

      <el-alert type="info" show-icon :closable="false" class="mb-3">
        <template #title>{{ $t('merchant.tip') }}</template>
      </el-alert>

      <el-table :data="tableData" class="mt-3" v-loading="loading">
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="name" :label="$t('merchant.name')" min-width="140" />
        <el-table-column prop="code" :label="$t('merchant.code')" min-width="140" />
        <el-table-column prop="contact" :label="$t('merchant.contact')" min-width="140" />
        <el-table-column :label="$t('system.status')" width="100">
          <template #default="scope">
            <el-tag :type="scope.row.status === 1 ? 'success' : 'info'" size="small">
              {{ scope.row.status === 1 ? $t('system.enabled') : $t('system.disabled') }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column :label="$t('system.operation')" width="160">
          <template #default="scope">
            <Update @click="open(scope.row.id)" />
            <Destroy @click="destroy(api, scope.row.id)" />
          </template>
        </el-table-column>
      </el-table>
      <Paginate />
    </div>

    <!-- v-if + key 强制销毁：避免 useShow 只在 setup 跑一次导致编辑串数据 -->
    <Dialog v-model="visible" :title="title" destroy-on-close>
      <Create v-if="visible" :key="id" :primary="id" :api="api" @close="close(search)" />
    </Dialog>
  </div>
</template>

<script lang="ts" setup>
import { computed, onMounted } from 'vue'
import Create from './create.vue'
import { useGetList } from '@/composables/curd/useGetList'
import { useDestroy } from '@/composables/curd/useDestroy'
import { useOpen } from '@/composables/curd/useOpen'
import { refreshMerchants } from '@/support/tenant'

const api = 'telegram/merchants'

const { data, query, search, reset, loading } = useGetList(api)
const { destroy, deleted } = useDestroy()
const { open, close, title, visible, id } = useOpen()

const tableData = computed(() => (data.value as any)?.data)

deleted(() => {
  refreshMerchants()
  search()
})

onMounted(() => {
  search()
})
</script>

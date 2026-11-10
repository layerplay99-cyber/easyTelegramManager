<template>
  <div>
    <Search :search="search" :reset="reset">
      <template v-slot:body>
        <el-form-item label="套餐名称" prop="name">
          <el-input v-model="(query as any).name" name="name" clearable />
        </el-form-item>
      </template>
    </Search>

    <div class="table-default">
      <Operate :show="open" />

      <el-alert type="info" show-icon :closable="false" class="mb-3">
        <template #title>
          套餐按「天数」售卖，购买后自动延长商户有效期；续期锚点为 max(当前时间, 原到期时间)，提前续费不亏天数。
        </template>
      </el-alert>

      <el-table :data="tableData" class="mt-3" v-loading="loading">
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="name" label="套餐名称" min-width="140" />
        <el-table-column prop="days" label="天数" width="100" />
        <el-table-column prop="price" label="价格" width="140" />
        <el-table-column prop="sort" label="排序" width="90" />
        <el-table-column label="状态" width="100">
          <template #default="scope">
            <el-tag :type="scope.row.status ? 'success' : 'info'" size="small">
              {{ scope.row.status ? '上架' : '下架' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="remark" label="说明" min-width="160" />
        <el-table-column label="操作" width="160">
          <template #default="scope">
            <Update @click="open(scope.row.id)" />
            <Destroy @click="destroy(api, scope.row.id)" />
          </template>
        </el-table-column>
      </el-table>
      <Paginate />
    </div>

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

const api = 'telegram/plans'

const { data, query, search, reset, loading } = useGetList(api)
const { destroy, deleted } = useDestroy()
const { open, close, title, visible, id } = useOpen()

const tableData = computed(() => (data.value as any)?.data)

deleted(() => search())

onMounted(() => {
  search()
})
</script>

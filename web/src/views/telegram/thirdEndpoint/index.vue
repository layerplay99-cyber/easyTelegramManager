<template>
  <div>
    <Search :search="search" :reset="reset">
      <template v-slot:body>
        <el-form-item label="接口名称" prop="name">
          <el-input v-model="query.name" name="name" clearable />
        </el-form-item>
        <el-form-item label="上游配置" prop="third_config_id">
          <el-select v-model="query.third_config_id" placeholder="请选择" clearable filterable>
            <el-option v-for="c in configs" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
        </el-form-item>
      </template>
    </Search>

    <div class="table-default">
      <Operate :show="open" />
      <el-table :data="tableData" class="mt-3" v-loading="loading">
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="上游配置" width="150">
          <template #default="scope">
            {{ configName(scope.row.third_config_id) }}
          </template>
        </el-table-column>
        <el-table-column prop="name" label="接口名称" />
        <el-table-column prop="method" label="方法" width="90" />
        <el-table-column prop="path_template" label="路径模板" min-width="220" show-overflow-tooltip />
        <el-table-column prop="timeout" label="超时(秒)" width="100" />
        <el-table-column label="状态" width="90">
          <template #default="scope">
            <label :class="scope.row.enabled === 1 ? 'text-green-600' : 'text-gray-400'">
              {{ scope.row.enabled === 1 ? '启用' : '停用' }}
            </label>
          </template>
        </el-table-column>
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
      <Create @close="close(reset)" :primary="id" :api="api" />
    </Dialog>
  </div>
</template>

<script lang="ts" setup>
import { computed, onMounted, ref, watch } from 'vue'
import Create from './create.vue'
import { useGetList } from '@/composables/curd/useGetList'
import { useDestroy } from '@/composables/curd/useDestroy'
import { useOpen } from '@/composables/curd/useOpen'
import http from '@/support/http'

const api = 'telegram/third/endpoint'

const { data, query, search, reset, loading } = useGetList(api)
const { destroy, deleted } = useDestroy()
const { open, close, title, visible, id } = useOpen()

const tableData = computed(() => (data.value as any)?.data)

const configs = ref<any[]>([])

const configName = (idValue: any) =>
  configs.value.find((c) => c.id === idValue)?.name || idValue || '-'

const loadConfigs = async () => {
  try {
    const { data: res } = await http.get('telegram/third/config')
    configs.value = res.data?.data || res.data || []
  } catch {
    configs.value = []
  }
}

watch(visible, (v) => {
  if (!v) search()
})

onMounted(() => {
  loadConfigs()
  search()
  deleted(reset)
})
</script>
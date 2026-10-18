<template>
  <div>
    <Search :search="search" :reset="reset">
      <template v-slot:body>
        <el-form-item :label="$t('third.name')" prop="name">
          <el-input v-model="query.name" name="name" clearable />
        </el-form-item>
        <el-form-item :label="$t('third.code')" prop="code">
          <el-input v-model="query.code" name="code" :placeholder="$t('third.codePlaceholder')" clearable />
        </el-form-item>
      </template>
    </Search>

    <div class="table-default">
      <el-alert type="info" show-icon :closable="false" class="mb-3">
        <template #title>
          <span>{{ $t('third.platformDefaultTip') }}</span>
        </template>
      </el-alert>

      <el-table :data="tableData" class="mt-3" v-loading="loading">
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="name" :label="$t('third.name')" />
        <el-table-column prop="code" :label="$t('third.platformCode')" min-width="150" />
        <!-- 以下两列由功能代码声明，每次同步以代码为准，后台改了也会被覆盖，故只读展示 -->
        <el-table-column :label="$t('third.method')" width="110">
          <template #default="scope">
            <span>{{ scope.row.method }}</span>
            <el-tag size="small" type="info" class="ml-1">{{ $t('third.codeDefined') }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="path_template" :label="$t('third.pathTemplate')" min-width="220" show-overflow-tooltip />
        <el-table-column prop="timeout" :label="$t('third.timeout')" width="100" />
        <el-table-column :label="$t('system.status')" width="90">
          <template #default="scope">
            <label :class="scope.row.enabled === 1 ? 'text-green-600' : 'text-gray-400'">
              {{ scope.row.enabled === 1 ? $t('system.enabled') : $t('system.disabled') }}
            </label>
          </template>
        </el-table-column>
        <el-table-column :label="$t('system.operation')" width="120">
          <template #default="scope">
            <Update @click="open(scope.row.id)" />
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
import { useOpen } from '@/composables/curd/useOpen'

const api = 'telegram/third/endpoint'

const { data, query, search, reset, loading } = useGetList(api)
const { open, close, title, visible, id } = useOpen()

const tableData = computed(() => (data.value as any)?.data)

watch(visible, (v) => {
  if (!v) search()
})

onMounted(() => {
  search()
})
</script>

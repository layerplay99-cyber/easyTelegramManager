<template>
  <div>
    <Search :search="search" :reset="reset">
      <template v-slot:body>
        <el-form-item :label="$t('cms.name')" prop="name">
          <el-input v-model="query.name" name="name" clearable />
        </el-form-item>
        <el-form-item :label="$t('cms.type')" prop="type">
          <el-select v-model="query.type" :placeholder="$t('system.pleaseSelect')">
            <el-option :value="1" :label="$t('cms.carousel')" />
            <el-option :value="2" :label="$t('cms.friendLink')" />
          </el-select>
        </el-form-item>
      </template>
    </Search>
    <div class="table-default">
      <Operate :show="open" />
      <el-table :data="tableData" class="mt-3" v-loading="loading">
        <el-table-column prop="name" :label="$t('cms.name')" />
        <el-table-column prop="content" :label="$t('cms.content')">
          <template #default="scope">
            <el-image :src="scope.row.content" v-if="scope.row.type === 1" width="100" />
            <div v-else>{{ scope.row.content }}</div>
          </template>
        </el-table-column>
        <el-table-column prop="url" :label="$t('table.link')">
          <template #default="scope">
            <el-link :href="scope.row.url" target="_blank">{{ scope.row.url }}</el-link>
            <el-tag type="warning">{{ scope.row.is_target ? $t('cms.openNewWindow') : $t('cms.openSameWindow') }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="type" :label="$t('cms.type')">
          <template #default="scope">
            <el-tag v-if="scope.row.type === 1">{{ $t('cms.carousel') }}</el-tag>
            <el-tag v-else type="success">{{ $t('cms.friendLink') }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="is_visible" :label="$t('cms.visible')">
          <template #default="scope">
            <Status :api="api" v-model="scope.row.is_visible" :id="scope.row.id" />
          </template>
        </el-table-column>
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

const api = 'cms/resource'

const { data, query, search, reset, loading } = useGetList(api)
const { destroy, deleted } = useDestroy()
const { open, close, title, visible, id } = useOpen()

const tableData = computed(() => data.value?.data)

onMounted(() => {
  search()
  deleted(reset)
})
</script>

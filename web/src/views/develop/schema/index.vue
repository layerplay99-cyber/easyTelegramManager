<template>
  <div>
    <Search :search="search" :reset="reset">
      <template v-slot:body>
        <el-form-item :label="$t('generate.schema.module')">
          <el-input v-model="query.module" name="module" clearable />
        </el-form-item>
        <el-form-item :label="$t('generate.schema.schemaName')">
          <el-input v-model="query.name" name="name" clearable />
        </el-form-item>
      </template>
    </Search>
    <div class="table-default">
      <Operate :show="open">
        <template #operate>
          <el-button @click="existSchemaVisible = true">{{ $t('generate.schema.selectExistTable') }}</el-button>
        </template>
      </Operate>
      <el-table :data="tableData" class="mt-3" v-loading="loading">
        <el-table-column prop="module" :label="$t('generate.schema.belongModule')" />
        <el-table-column prop="name" :label="$t('generate.schema.schemaName')" />
        <el-table-column prop="columns" :label="$t('generate.schema.fields')">
          <template #default="scope">
            <el-button size="small" type="success" @click="view(scope.row.id)"><Icon name="eye" class="w-3 mr-1" /> {{ $t('system.detail') }}</el-button>
          </template>
        </el-table-column>
        <el-table-column prop="is_soft_delete" :label="$t('generate.schema.softDelete')">
          <template #default="scope">
            <el-tag v-if="scope.row.is_soft_delete">{{ $t('system.yes') }}</el-tag>
            <el-tag type="danger" v-else>{{ $t('system.no') }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" :label="$t('system.createTime')" />
        <el-table-column :label="$t('system.operation')" width="250">
          <template #default="scope">
            <router-link :to="'/develop/generate/' + scope.row.id">
              <el-button type="warning" size="small"><Icon name="wrench-screwdriver" class="w-3 mr-1" /> {{ $t('generate.schema.generateCode') }}</el-button>
            </router-link>
            <Destroy @click="destroy(api, scope.row.id)" class="ml-2" />
          </template>
        </el-table-column>
      </el-table>
      <Paginate />
    </div>

    <!-- schema 创建 -->
    <Dialog v-model="visible" :title="title" width="650px" destroy-on-close>
      <Create @close="close(reset)" :api="api" />
    </Dialog>

    <!-- schema 表结构 -->
    <Dialog v-model="schemaVisible" :title="$t('generate.schema.schemaStructure')" width="650px" destroy-on-close>
      <Show :id="id" :api="api" />
    </Dialog>

    <!-- 从已有 Schema 选择 -->
    <Dialog v-model="existSchemaVisible" :title="$t('generate.schema.selectExistTable')" width="650px" destroy-on-close>
      <addExistSchema :api="api" @close="closeExistSchemaVisible" />
    </Dialog>
  </div>
</template>

<script lang="ts" setup>
// @ts-nocheck
import { computed, onMounted, ref } from 'vue'
import Create from './create.vue'
import Show from './show.vue'
import addExistSchema from './addExistSchema.vue'
import { useGetList } from '@/composables/curd/useGetList'
import { useDestroy } from '@/composables/curd/useDestroy'
import { useOpen } from '@/composables/curd/useOpen'
import { t } from '@/support/helper'

const schemaVisible = ref<boolean>(false)
const existSchemaVisible = ref<boolean>(false)

const api = 'schema'

const { data, query, search, reset, loading } = useGetList(api)
const { destroy, deleted } = useDestroy(t('generate.schema.confirmDelete'))
const { open, close, title, visible, id } = useOpen()

const tableData = computed(() => data.value?.data)

const view = primaryId => {
  schemaVisible.value = true

  id.value = primaryId
}
const closeExistSchemaVisible = () => {
  existSchemaVisible.value = false
  reset()
}
onMounted(() => {
  search()

  deleted(reset)
})
</script>

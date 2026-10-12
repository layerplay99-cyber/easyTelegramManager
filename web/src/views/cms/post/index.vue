<template>
  <div>
    <Search :search="search" :reset="reset">
      <template v-slot:body>
        <el-form-item :label="$t('cms.title')">
          <el-input v-model="query.title" clearable :placeholder="$t('cms.titlePlaceholder')" />
        </el-form-item>
        <el-form-item :label="$t('cms.category')">
          <el-tree-select v-model="query.category_id" value-key="id" :placeholder="$t('cms.selectCategory')" clearable :data="categories" check-strictly :props="{ value: 'id', label: 'name' }" />
        </el-form-item>
      </template>
    </Search>
    <div class="table-default">
      <div class="pt-5 pl-2">
        <router-link to="/cms/articles/create">
          <Add :text="$t('cms.createPost')" />
        </router-link>
      </div>
      <el-table :data="tableData" class="mt-3" v-loading="loading" @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="55" />
        <el-table-column prop="id" label="ID" width="50" />
        <el-table-column prop="title" :label="$t('cms.title')" />
        <el-table-column prop="category" :label="$t('cms.category')" />
        <el-table-column prop="author_name" :label="$t('cms.author')" />
        <el-table-column prop="status" :label="$t('system.status')">
          <template #default="scope">
            <Status v-model="scope.row.status" :id="scope.row.id" api="/cms/post" />
          </template>
        </el-table-column>
        <el-table-column prop="order" :label="$t('cms.sort')" width="100" />
        <el-table-column prop="updated_at" :label="$t('system.updateTime')" width="200" />
        <el-table-column :label="$t('system.operation')" width="200">
          <template #default="scope">
            <router-link :to="'/cms/articles/create/' + scope.row.id">
              <Update />
            </router-link>
            <Destroy @click="destroy(api, scope.row.id)" class="ml-2" />
          </template>
        </el-table-column>
      </el-table>
      <div class="flex justify-between">
        <div class="pt-4">
          <Destroy :text="$t('system.batchDelete')" @click="multiDel" />
          <Update :text="$t('cms.batchPublish')" @click="multiPublish" />
        </div>
        <Paginate />
      </div>
    </div>
  </div>
</template>

<script lang="ts" setup>
// @ts-nocheck
import { computed, onMounted, ref } from 'vue'
import { useGetList } from '@/composables/curd/useGetList'
import { useDestroy } from '@/composables/curd/useDestroy'
import { useOpen } from '@/composables/curd/useOpen'
import Destroy from '@/components/admin/buttons/destroy.vue'
import http from '@/support/http'
import Message from '@/support/message'
import { t } from '@/support/helper'

const api = 'cms/post'

const { data, query, search, reset, loading } = useGetList(api)
const { destroy, deleted } = useDestroy()
const { open, close, title, visible, id } = useOpen()

const tableData = computed(() => data.value?.data)

onMounted(() => {
  search()
  deleted(reset)
})

// 多选
const multiSelect = ref([])
const handleSelectionChange = posts => {
  multiSelect.value = posts
}

const multiDel = () => {
  let ids = ''
  multiSelect.value.forEach(item => {
    ids += item.id + ','
  })
  if (ids.length === 0) {
    Message.error(t('cms.selectPostsToDelete'))
  } else {
    destroy('/cms/post', ids.substring(0, ids.length - 1))
  }
}

const multiPublish = () => {
  let ids = ''
  multiSelect.value.forEach(item => {
    if (item.status !== 1) {
      ids += item.id + ','
    }
  })

  if (ids.length === 0) {
    Message.error(t('cms.selectUnpublishedPosts'))
  } else {
    Message.confirm(t('cms.confirmBatchPublish'), () => {
      http.put('/cms/post/enable/' + ids.substring(0, ids.length - 1)).then(r => {})
      reset()
    })
  }
}

const categories = ref()
http.get('cms/category').then(r => {
  categories.value = r.data.data
})
</script>

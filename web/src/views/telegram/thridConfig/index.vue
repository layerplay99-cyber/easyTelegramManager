<template>
    <div>
        <Search :search="search" :reset="reset">
            <template v-slot:body>
                <el-form-item label="名称" prop="name">
                    <el-input v-model="query.name" name="name" clearable />
                </el-form-item>
            </template>
        </Search>
        <div class="table-default">
            <Operate :show="open" />
            <el-table :data="tableData" class="mt-3" v-loading="loading">
                <el-table-column prop="name" label="名称" />
                <el-table-column prop="api_url" label="api" />
                <el-table-column prop="token" label="token" />
                <el-table-column prop="public_key" label="公钥" />
                <el-table-column prop="secrept_key" label="密钥" />
                <el-table-column prop="created_at" label="创建时间" />
                <el-table-column prop="updated_at" label="更新时间" />
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
import { computed, onMounted } from 'vue'
import Create from './create.vue'
import { useGetList } from '@/composables/curd/useGetList'
import { useDestroy } from '@/composables/curd/useDestroy'
import { useOpen } from '@/composables/curd/useOpen'

const api = 'telegram/third/config'

const { data, query, search, reset, loading } = useGetList(api)
// useDestroy 的第一个参数是「删除确认框的提示文案」，不是接口地址。
// 传 api 会让弹窗显示 "telegram/third/config?"，这里保持默认文案即可。
const { destroy, deleted } = useDestroy()
const { open, close, title, visible, id } = useOpen()

const tableData = computed(() => data.value?.data)

onMounted(() => {
    search()
    deleted(reset)
})
</script>

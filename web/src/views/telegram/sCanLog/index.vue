<template>
    <div>
        <Search :search="search" :reset="reset">
            <template v-slot:body>
                <el-form-item label="被扫电话" prop="phone">
                    <el-input v-model="query.phone" name="phone" clearable />
                </el-form-item>
                <el-form-item label="N天内活跃" prop="days">
                    <el-input-number v-model="query.days" name="days" :min="1" />
                </el-form-item>
            </template>
        </Search>
        <div class="table-default">
            <Operate :show="open" />
            <el-table :data="tableData" class="mt-3" v-loading="loading">
                <el-table-column prop="phone" label="被扫电话" />
                <el-table-column prop="days" label="N天内活跃" />
                <el-table-column prop="username" label="用户名" />
                <el-table-column prop="bio" label="简介" />
                <el-table-column prop="avatar_path" label="用户头像" />
                <el-table-column prop="created_at" label="创建时间" />
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

const api = 'telegram/s/can/log'

const { data, query, search, reset, loading } = useGetList(api)
const { destroy, deleted } = useDestroy()
const { open, close, title, visible, id } = useOpen()

const tableData = computed(() => data.value?.data)

onMounted(() => {
    search()
    deleted(reset)
})
</script>

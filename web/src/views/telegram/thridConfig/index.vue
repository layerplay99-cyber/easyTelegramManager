<template>
    <div>
        <Search :search="search" :reset="reset">
            <template v-slot:body>
                <el-form-item :label="$t('third.name')" prop="name">
                    <el-input v-model="query.name" name="name" clearable />
                </el-form-item>
            </template>
        </Search>
        <div class="table-default">
            <Operate :show="open" />
            <el-table :data="tableData" class="mt-3" v-loading="loading">
                <el-table-column prop="name" :label="$t('third.name')" />
                <el-table-column prop="api_url" :label="$t('third.apiUrl')" />
                <el-table-column prop="token" :label="$t('third.token')" />
                <el-table-column prop="public_key" :label="$t('third.publicKey')" />
                <el-table-column prop="secrept_key" :label="$t('third.secretKey')" />
                <el-table-column prop="created_at" :label="$t('system.createTime')" />
                <el-table-column prop="updated_at" :label="$t('system.updateTime')" />
                <el-table-column :label="$t('system.operation')" width="280">
                    <template #default="scope">
                        <el-button type="warning" size="small" @click="goEndpoints(scope.row.id)">
                            {{ $t('third.endpointConfig') }}
                        </el-button>
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
import { useRouter } from 'vue-router'

const api = 'telegram/third/config'

const router = useRouter()

// 跳转到「上游接口配置」页，并自动选中该上游
const goEndpoints = (thirdConfigId: number) => {
    router.push({ path: '/activity/tconfendpoint', query: { third_config_id: thirdConfigId } })
}

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

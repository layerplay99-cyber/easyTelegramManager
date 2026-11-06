<template>
  <div v-if="isSuperAdmin" class="flex items-center mr-2 min-w-[10rem]">
    <el-select
      v-model="active"
      size="small"
      clearable
      :placeholder="$t('third.platformShared')"
      @change="onChange"
    >
      <el-option :label="$t('third.platformShared')" :value="0" />
      <el-option v-for="item in merchants" :key="item.id" :label="item.name" :value="item.id" />
    </el-select>
  </div>
</template>

<script lang="ts" setup>
import { onMounted, ref } from 'vue'
import http from '@/support/http'
import { useUserStore } from '@/stores/modules/user'
import { getActiveTenantId, setActiveTenantId } from '@/support/tenant'

const isSuperAdmin = useUserStore().isSuperAdmin()
const merchants = ref<Array<{ id: number; name: string }>>([])
const active = ref<number>(getActiveTenantId())

onMounted(() => {
  if (!isSuperAdmin) return

  http
    .get('telegram/merchants', {})
    .then(r => {
      const payload = r.data?.data
      merchants.value = Array.isArray(payload) ? payload : (payload?.data ?? [])
    })
    .catch(() => {
      merchants.value = []
    })
})

const onChange = (value: number | '' | undefined | null) => {
  const id = typeof value === 'number' && value > 0 ? value : 0
  setActiveTenantId(id > 0 ? id : null)
  active.value = id
}
</script>

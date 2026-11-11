<template>
  <div v-if="isSuperAdmin" class="flex items-center mr-2 min-w-0 shrink">
    <span class="tenant-label hidden xl:inline">{{ $t('merchant.scopeLabel') }}</span>

    <!-- 自己渲染当前选中值：el-select 的内部文字不跟随顶栏 currentColor，
         深色主题下会和 header 背景糊在一起，看不出到底选了哪个商户。 -->
    <el-dropdown trigger="click" :disabled="loading" @command="onChange">
      <button type="button" class="tenant-trigger" :title="currentLabel">
        <span class="tenant-dot" :class="active > 0 ? 'is-merchant' : 'is-platform'" />
        <span class="tenant-trigger__label">{{ currentLabel }}</span>
        <Icon name="chevron-down" class="tenant-chevron" />
      </button>
      <template #dropdown>
        <el-dropdown-menu class="tenant-menu">
          <el-dropdown-item :command="0" :disabled="active === 0">
            {{ $t('third.platformShared') }}
          </el-dropdown-item>
          <el-dropdown-item
            v-for="item in merchantOptions"
            :key="item.id"
            :command="item.id"
            :disabled="item.id === active"
          >
            {{ item.status === 1 ? item.name : item.name + '（' + $t('system.disabled') + '）' }}
          </el-dropdown-item>
          <el-dropdown-item v-if="!loading && !merchantOptions.length" disabled>
            {{ $t('merchant.noMerchant') }}
          </el-dropdown-item>
        </el-dropdown-menu>
      </template>
    </el-dropdown>

    <el-tooltip :content="$t('merchant.manageTip')" placement="bottom">
      <el-button link size="small" class="ml-1 px-1" @click="goMerchants">
        <Icon name="cog-6-tooth" class="w-4 h-4" />
      </el-button>
    </el-tooltip>
  </div>
</template>

<script lang="ts" setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useUserStore } from '@/stores/modules/user'
import {
  getActiveTenantId,
  loadMerchantOptions,
  merchantName,
  merchantOptions,
  merchantVersion,
  setActiveTenantId
} from '@/support/tenant'

const router = useRouter()
const isSuperAdmin = useUserStore().isSuperAdmin()
const active = ref<number>(getActiveTenantId())
const loading = ref(false)

const currentLabel = computed(() => merchantName(active.value))

const load = () => {
  if (!isSuperAdmin) return

  loading.value = true
  loadMerchantOptions(true)
    .then(() => {
      // 选中的商户被删掉后不能留一个悬空的归属
      if (active.value > 0 && !merchantOptions.value.some(item => item.id === active.value)) {
        setActiveTenantId(null)
        active.value = 0
      }
    })
    .finally(() => {
      loading.value = false
    })
}

watch(merchantVersion, load)
onMounted(load)

const onChange = (value: number | '' | undefined | null) => {
  const id = typeof value === 'number' && value > 0 ? value : 0
  setActiveTenantId(id > 0 ? id : null)
  active.value = id
}

const goMerchants = () => {
  router.push('/platform/merchants')
}
</script>

<style scoped>
/* 顶栏深浅色主题下都用继承色：顶栏图标就是这么渲染的，深色下是白色 */
.tenant-label {
  margin-right: 0.5rem;
  font-size: 0.75rem;
  line-height: 1rem;
  white-space: nowrap;
  color: inherit;
  opacity: 0.8;
}

.tenant-trigger {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  /* 顶栏空间有限（搜索/语言/主题 + 商户 + 个人），固定 10rem 会把个人入口挤出视口，
     这里改成在 5.5~9rem 之间随可用宽度收缩，文案自带省略号 */
  width: auto;
  min-width: 5.5rem;
  max-width: 9rem;
  height: 1.75rem;
  padding: 0 0.55rem;
  border: 1px solid var(--el-border-color);
  border-radius: 0.375rem;
  background: transparent;
  color: inherit;
  font-size: 0.75rem;
  line-height: 1;
  cursor: pointer;
}

.tenant-trigger:hover {
  border-color: var(--el-color-primary);
}

.tenant-trigger__label {
  flex: 1 1 auto;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  text-align: left;
}

.tenant-dot {
  flex: none;
  width: 0.4rem;
  height: 0.4rem;
  border-radius: 9999px;
  background: var(--el-color-info);
}

.tenant-dot.is-merchant {
  background: var(--el-color-primary);
}

.tenant-chevron {
  flex: none;
  width: 0.875rem;
  height: 0.875rem;
  opacity: 0.75;
}
</style>

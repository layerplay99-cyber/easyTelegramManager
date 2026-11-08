import { ref } from 'vue'
import { useUserStore } from '@/stores/modules/user'
import http from '@/support/http'
import { t } from '@/support/helper'

const STORAGE_KEY = 'platform_active_tenant_id'

/** 商户在后台被增删改时递增，各处据此重新拉取 */
export const merchantVersion = ref(0)

export interface MerchantOption {
  id: number
  name: string
  status: number
}

/** 商户名映射：顶栏 / 列表 / 表单共用一个缓存，不再各处把 tenant_id 当文本渲染 */
export const merchantOptions = ref<MerchantOption[]>([])
const merchantLoaded = ref(false)
let inflight: Promise<void> | null = null

/**
 * 平台超管「当前操作商户」
 *
 * 超管在顶栏选中某个商户后，所有后台新建表单都会带上 tenant_id，
 * 这样超管代商户配置的功能 / 上游 / 机器人，商户登录后就能看到。
 * 不选（值为 0）表示建平台共享数据。
 *
 * 商户用户永远返回 0 —— 他们的归属由后端 MerchantContext 按登录用户强制继承，
 * 不允许通过前端参数改到别的商户。
 */
export function isPlatformSuperAdmin(): boolean {
  return useUserStore().isSuperAdmin()
}

/** 商户管理接口只有平台超管可访问；商户用户不请求，用「本商户」兜底文案 */
export function loadMerchantOptions(force = false): Promise<void> {
  if (!isPlatformSuperAdmin()) {
    return Promise.resolve()
  }

  if (merchantLoaded.value && !force) {
    return Promise.resolve()
  }

  if (inflight) {
    return inflight
  }

  inflight = http
    .get('telegram/merchants', { all: 1 })
    .then(r => {
      const payload = r.data?.data
      merchantOptions.value = Array.isArray(payload) ? payload : (payload?.data ?? [])
      merchantLoaded.value = true
    })
    .catch(() => {
      merchantOptions.value = []
    })
    .finally(() => {
      inflight = null
    })

  return inflight
}

/** 归属统一转成商户名：0 → 平台共享，查不到 → 本商户 / #id */
export function merchantName(id: number | string | null | undefined): string {
  const tenantId = Number(id) || 0

  if (tenantId <= 0) {
    return t('third.platformShared')
  }

  const item = merchantOptions.value.find(m => m.id === tenantId)
  if (item) {
    return item.name
  }

  // 商户用户拿不到商户列表，显示「本商户」，不再露出裸 ID
  if (!isPlatformSuperAdmin()) {
    return t('third.currentMerchant')
  }

  return `#${tenantId}`
}

export function refreshMerchants(): void {
  merchantVersion.value++
  merchantLoaded.value = false
  void loadMerchantOptions(true)
}

export function getActiveTenantId(): number {
  if (!isPlatformSuperAdmin()) {
    return 0
  }

  const raw = localStorage.getItem(STORAGE_KEY)
  const value = raw === null ? 0 : Number.parseInt(raw, 10)

  return Number.isFinite(value) && value > 0 ? value : 0
}

export function setActiveTenantId(id: number | null): void {
  if (id && id > 0) {
    localStorage.setItem(STORAGE_KEY, String(id))
  } else {
    localStorage.removeItem(STORAGE_KEY)
  }
}

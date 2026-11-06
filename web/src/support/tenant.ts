import { useUserStore } from '@/stores/modules/user'

const STORAGE_KEY = 'platform_active_tenant_id'

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

<template>
  <div>
    <div class="table-default">
      <div class="flex items-center gap-4 mb-3">
        <el-statistic title="我的余额" :value="balance" />
        <el-statistic title="服务到期时间" :value="expiredAt || '永久'" />
        <el-button type="primary" class="ml-auto" @click="openPurchase">购买套餐</el-button>
        <el-button v-if="isSuperAdmin" @click="openAdjust">调账</el-button>
      </div>

      <el-table :data="tableData" v-loading="loading">
        <el-table-column prop="order_no" label="订单号" min-width="200" />
        <el-table-column prop="plan_name" label="套餐" min-width="140" />
        <el-table-column prop="plan_days" label="天数" width="90" />
        <el-table-column prop="price" label="金额" width="140" />
        <el-table-column label="状态" width="100">
          <template #default="scope">
            <el-tag :type="scope.row.status === 1 ? 'success' : 'info'" size="small">
              {{ statusText(scope.row.status) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="expired_to" label="新到期时间" min-width="170">
          <template #default="scope">{{ ts(scope.row.expired_to) }}</template>
        </el-table-column>
        <el-table-column prop="created_at" label="下单时间" min-width="170">
          <template #default="scope">{{ ts(scope.row.created_at) }}</template>
        </el-table-column>
      </el-table>
      <Paginate />
    </div>

    <Dialog v-model="visible" title="购买套餐" destroy-on-close width="480px">
      <el-form label-width="90px">
        <el-form-item label="选择套餐">
          <el-select v-model="planId" placeholder="请选择套餐" style="width: 100%">
            <el-option
              v-for="item in plans"
              :key="item.id"
              :label="`${item.name}（${item.days} 天 / ${item.price}）`"
              :value="item.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="我的余额">
          <span>{{ balance }}</span>
        </el-form-item>
      </el-form>
      <div class="flex justify-end">
        <el-button @click="visible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submitPurchase">确认购买</el-button>
      </div>
    </Dialog>

    <!-- 调账：仅平台超管（给商户账号充值/扣减余额，扣减传负数） -->
    <Dialog v-model="adjustVisible" title="调账" destroy-on-close width="520px">
      <el-form label-width="100px">
        <el-form-item label="账号">
          <el-select
            v-model="adjustForm.user_id"
            filterable
            remote
            reserve-keyword
            placeholder="输入账号名 / 邮箱 / ID 搜索"
            :remote-method="searchAccounts"
            :loading="accountLoading"
            style="width: 100%"
          >
            <el-option
              v-for="item in accountOptions"
              :key="item.id"
              :label="`#${item.id} ${item.name}（${item.merchant_name}）余额 ${item.balance}`"
              :value="item.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="金额">
          <el-input v-model="adjustForm.amount" placeholder="正数为充值，负数为扣减，如 100 / -50" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="adjustForm.remark" type="textarea" :rows="2" placeholder="必填，会写进余额流水的备注" />
        </el-form-item>
        <el-alert
          type="info"
          :closable="false"
          show-icon
          title="调账立即生效并记入余额流水；余额不允许为负，扣减超额会被拒绝。"
        />
      </el-form>
      <div class="flex justify-end mt-3">
        <el-button @click="adjustVisible = false">取消</el-button>
        <el-button type="primary" :loading="adjusting" @click="submitAdjust">确认调账</el-button>
      </div>
    </Dialog>
  </div>
</template>

<script lang="ts" setup>
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import http from '@/support/http'
import { useGetList } from '@/composables/curd/useGetList'

const api = 'telegram/plan/orders'

const { data, search, loading } = useGetList(api)

const tableData = computed(() => (data.value as any)?.data)

const balance = ref('0')
const expiredAt = ref('')
const plans = ref<any[]>([])
const visible = ref(false)
const planId = ref<number>()
const submitting = ref(false)

// 调账（仅平台超管可见）
const isSuperAdmin = ref(false)
const adjustVisible = ref(false)
const adjusting = ref(false)
const accountLoading = ref(false)
const accountOptions = ref<any[]>([])
const adjustForm = ref<{ user_id?: number; amount: string; remark: string }>({
  user_id: undefined,
  amount: '',
  remark: ''
})

const statusText = (status: number) => ({ 0: '待支付', 1: '已支付', 2: '已取消' }[status] || '-')

// CatchModel 时间戳是 Unix 秒
const ts = (value: number | null) => {
  if (!value) return '-'
  const d = new Date(value * 1000)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}

// 响应体是 { code, message, data }，真实数据在 res.data.data（少解包一层
// 会把响应体对象当列表遍历——选择套餐里的三条 undefined 就是它）
const body = (res: any) => res?.data?.data

const loadSummary = async () => {
  const res: any = await http.get('telegram/plan/orders/balance')
  balance.value = body(res)?.balance ?? '0'
  expiredAt.value = body(res)?.expired_at ?? ''
  isSuperAdmin.value = !!body(res)?.is_super_admin
}

const searchAccounts = async (keyword: string) => {
  accountLoading.value = true
  try {
    const res: any = await http.get('telegram/plan/orders/accounts', { keyword })
    accountOptions.value = body(res) ?? []
  } catch {
    accountOptions.value = []
  } finally {
    accountLoading.value = false
  }
}

const openAdjust = () => {
  adjustForm.value = { user_id: undefined, amount: '', remark: '' }
  accountOptions.value = []
  searchAccounts('')
  adjustVisible.value = true
}

const submitAdjust = async () => {
  const { user_id, amount, remark } = adjustForm.value

  if (!user_id) {
    ElMessage.warning('请选择账号')
    return
  }

  if (!amount || Number.isNaN(Number(amount)) || Number(amount) === 0) {
    ElMessage.warning('金额必须是非 0 数字（负数为扣减）')
    return
  }

  if (!remark.trim()) {
    ElMessage.warning('调账必须填写备注')
    return
  }

  adjusting.value = true
  try {
    const res: any = await http.post('telegram/plan/orders/adjust-balance', {
      user_id,
      amount,
      remark: remark.trim()
    })
    ElMessage.success(`调账成功，当前余额 ${body(res)?.balance ?? ''}`)
    adjustVisible.value = false
    search()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '调账失败')
  } finally {
    adjusting.value = false
  }
}

const openPurchase = async () => {
  const res: any = await http.get('telegram/plans', { all: 1 })
  plans.value = body(res) ?? []
  planId.value = undefined
  visible.value = true
}

const submitPurchase = async () => {
  if (!planId.value) {
    ElMessage.warning('请选择套餐')
    return
  }

  submitting.value = true
  try {
    await http.post('telegram/plan/orders/purchase', { plan_id: planId.value })
    ElMessage.success('购买成功，有效期已延长')
    visible.value = false
    await loadSummary()
    search()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '购买失败')
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  search()
  loadSummary()
})
</script>

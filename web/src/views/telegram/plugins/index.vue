<template>
  <div class="table-default">
    <div class="flex items-center justify-between mb-3">
      <div class="flex items-center gap-2">
        <el-button type="primary" :loading="uploading" @click="pickFile">上传插件</el-button>
        <el-button @click="openSettings">审核设置</el-button>
        <span v-if="uploading" class="text-xs text-gray-500">正在校验并安装 {{ uploadName }} …</span>
      </div>
      <div class="flex items-center gap-2">
        <el-select v-model="status" clearable placeholder="全部状态" style="width: 140px" @change="load">
          <el-option label="已启用" value="installed" />
          <el-option label="已停用" value="disabled" />
          <el-option label="审核未通过" value="rejected" />
        </el-select>
        <el-button @click="load">刷新</el-button>
      </div>
    </div>

    <input ref="fileInput" type="file" accept=".zip" class="hidden" @change="onFileChange" />

    <el-table :data="list" v-loading="loading" class="mt-2">
      <el-table-column prop="name" label="插件名称" min-width="160">
        <template #default="scope">
          <div class="font-medium">{{ scope.row.name }}</div>
          <div class="text-xs text-gray-400">{{ scope.row.slug }} · {{ scope.row.version || '未标版本' }}</div>
        </template>
      </el-table-column>
      <el-table-column prop="author" label="作者" width="120">
        <template #default="scope">{{ scope.row.author || '-' }}</template>
      </el-table-column>
      <el-table-column label="归属商户" width="120"><template #default="scope"><el-tag v-if="(scope.row.manifest || {}).merchant_id" type="warning" size="small">{{ scope.row.manifest.merchant_id }}</el-tag><el-tag v-else type="success" size="small">官方</el-tag></template></el-table-column>
      <el-table-column label="状态" width="110">
        <template #default="scope">
          <el-tag v-if="scope.row.status === 'installed'" type="success" size="small">已启用</el-tag>
          <el-tag v-else-if="scope.row.status === 'disabled'" type="info" size="small">已停用</el-tag>
          <el-tag v-else type="danger" size="small">审核未通过</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="功能数" width="90">
        <template #default="scope">{{ (scope.row.feature_ids || []).length }}</template>
      </el-table-column>
      <el-table-column label="审核" min-width="200">
        <template #default="scope">
          <div class="text-xs">
            <span class="mr-2">脚本：
              <span :class="errorCount(scope.row) ? 'text-red-500' : 'text-green-600'">
                {{ errorCount(scope.row) }} 错误
              </span>
              / {{ warnCount(scope.row) }} 提示
            </span>
            <span v-if="scope.row.ai_review">
              AI：<el-tag size="small" :type="scope.row.ai_review.verdict === 'approve' ? 'success' : 'danger'">
                {{ scope.row.ai_review.verdict === 'approve' ? '通过' : '拒绝' }}
              </el-tag>
            </span>
            <span v-else class="text-gray-400">AI：未参与</span>
          </div>
        </template>
      </el-table-column>
      <el-table-column label="安装时间" width="160">
        <template #default="scope">{{ formatTime(scope.row.created_at) }}</template>
      </el-table-column>
      <el-table-column label="操作" width="260">
        <template #default="scope">
          <el-button type="primary" link size="small" @click="openDetail(scope.row)">审核详情</el-button>
          <el-button
            v-if="scope.row.status === 'installed'"
            type="warning"
            link
            size="small"
            @click="toggle(scope.row, 'disabled')"
          >停用</el-button>
          <el-button
            v-if="scope.row.status === 'disabled'"
            type="success"
            link
            size="small"
            @click="toggle(scope.row, 'installed')"
          >启用</el-button>
          <el-button
            v-if="scope.row.status !== 'rejected'"
            type="danger"
            link
            size="small"
            @click="remove(scope.row)"
          >卸载</el-button>
        </template>
      </el-table-column>
    </el-table>

    <!-- 审核详情 -->
    <Dialog v-model="detailVisible" title="审核详情" width="760px">
      <div v-if="detail" class="text-sm">
        <p class="mb-2"><b>{{ detail.name }}</b>（{{ detail.slug }}）</p>
        <el-alert
          v-if="detail.status === 'rejected'"
          type="error"
          :closable="false"
          title="该插件未通过审核，未安装到服务器"
          class="mb-3"
        />

        <div v-if="detail.audit_report?.errors?.length" class="mb-3">
          <div class="font-medium text-red-600 mb-1">脚本审核 · 错误</div>
          <ul class="list-disc pl-5">
            <li v-for="(e, i) in detail.audit_report.errors" :key="'e' + i">
              {{ e.file }}:{{ e.line }} — {{ e.message }}
            </li>
          </ul>
        </div>

        <div v-if="detail.audit_report?.warnings?.length" class="mb-3">
          <div class="font-medium text-amber-600 mb-1">脚本审核 · 提示</div>
          <ul class="list-disc pl-5">
            <li v-for="(w, i) in detail.audit_report.warnings" :key="'w' + i">
              {{ w.file }}:{{ w.line }} — {{ w.message }}
            </li>
          </ul>
        </div>

        <div v-if="!detail.audit_report?.errors?.length && !detail.audit_report?.warnings?.length" class="text-green-600 mb-3">
          脚本审核未发现问题。
        </div>

        <div v-if="detail.ai_review" class="mb-2">
          <div class="font-medium mb-1">
            AI 审核（{{ detail.ai_review.model }}）
            <el-tag size="small" :type="detail.ai_review.verdict === 'approve' ? 'success' : 'danger'">
              {{ detail.ai_review.verdict === 'approve' ? '通过' : '拒绝' }}
            </el-tag>
            <span class="text-xs text-gray-400 ml-2">评分 {{ detail.ai_review.score }}</span>
          </div>
          <ul v-if="detail.ai_review.reasons?.length" class="list-disc pl-5">
            <li v-for="(r, i) in detail.ai_review.reasons" :key="'r' + i">{{ r }}</li>
          </ul>
          <div v-else class="text-gray-500">无风险说明。</div>
        </div>
        <div v-else class="text-gray-500">AI 审核未参与（后台未开启）。</div>
      </div>
    </Dialog>

    <!-- 审核设置 -->
    <Dialog v-model="settingsVisible" title="审核设置" width="560px">
      <el-form :model="settings" label-width="120px" v-loading="settingsLoading">
        <el-form-item label="AI 参与审核">
          <el-switch v-model="settings.ai_enabled" :active-value="1" :inactive-value="0" />
          <div class="text-xs text-gray-500 mt-1">
            开启后，脚本审核通过的插件还会再让模型看一遍代码；模型拒绝则不安装（可勾选强制安装）。
          </div>
        </el-form-item>
        <el-form-item label="接口地址">
          <el-input v-model="settings.ai_base_url" placeholder="https://api.openai.com/v1" />
        </el-form-item>
        <el-form-item label="模型">
          <el-input v-model="settings.ai_model" placeholder="gpt-4o-mini" />
        </el-form-item>
        <el-form-item label="API Key">
          <el-input
            v-model="settings.ai_api_key"
            type="password"
            show-password
            :placeholder="settings.ai_api_key_set ? '已配置，留空表示不修改' : 'sk-...'"
          />
        </el-form-item>
        <el-form-item label="超时（秒）">
          <el-input-number v-model="settings.ai_timeout" :min="10" :max="120" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="settingsSaving" @click="saveSettings">保存设置</el-button>
        </el-form-item>
      </el-form>
    </Dialog>
  </div>
</template>

<script lang="ts" setup>
import { onMounted, ref } from 'vue'
import { ElMessageBox } from 'element-plus'
import http from '@/support/http'
import Message from '@/support/message'

const api = 'telegram/plugins'

const list = ref<any[]>([])
const loading = ref(false)
const status = ref('')

const fileInput = ref<HTMLInputElement>()
const uploading = ref(false)
const uploadName = ref('')

const detailVisible = ref(false)
const detail = ref<any>(null)

const settingsVisible = ref(false)
const settingsLoading = ref(false)
const settingsSaving = ref(false)
const settings = ref<any>({
  ai_enabled: 0,
  ai_base_url: 'https://api.openai.com/v1',
  ai_model: 'gpt-4o-mini',
  ai_api_key: '',
  ai_api_key_set: false,
  ai_timeout: 30
})

const errorCount = (row: any) => (row?.audit_report?.errors || []).length
const warnCount = (row: any) => (row?.audit_report?.warnings || []).length

const formatTime = (ts: any) => {
  if (!ts) return '-'
  const n = Number(ts)
  const d = new Date(n > 1e12 ? n : n * 1000)
  const p = (x: number) => String(x).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

const load = async () => {
  loading.value = true
  try {
    const { data } = await http.get(api, { params: { status: status.value || undefined } })
    // 响应只有一层：{ code, message, data: { data: [], total… } }
    const payload = data.data
    // 兼容两种响应：分页器顶层展开 { code, message, data: [行数组], total }
    // 与双层 { code, message, data: { data: [行数组], total } }。
    // 之前写死取 data.data.data，实际响应是前者 → 恒为 undefined，表格永远空白
    list.value = Array.isArray(payload) ? payload : (payload?.data || [])
  } catch (e) {
    list.value = []
  } finally {
    loading.value = false
  }
}

const pickFile = () => {
  fileInput.value?.click()
}

// 上传 + 安装：AI 审核拒绝时，允许平台超管确认后带 force 重试一次
const upload = async (file: File, forced: boolean) => {
  const form = new FormData()
  form.append('file', file)
  if (forced) form.append('force', '1')

  uploading.value = true
  uploadName.value = file.name

  try {
    // 安装要跑脚本审核 + AI 审核（AI 单次最长 120s），全局 10s 超时不够；
    // 后端 PHP 已无执行时限，网关 fastcgi_read_timeout 配到 180s
    await http.post(api, form, { timeout: 180000 })
    Message.success('插件已安装，功能已同步到功能列表')
    load()
  } finally {
    uploading.value = false
    uploadName.value = ''
  }
}

const onFileChange = async (evt: Event) => {
  const input = evt.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''

  if (!file) return

  try {
    await upload(file, false)
  } catch (e: any) {
    const msg = e?.response?.data?.message || '安装失败'

    // 只有 AI 结论可以推翻：脚本审核的错误不给强制
    if (!msg.includes('AI 审核未通过')) {
      Message.error(msg)
      return
    }

    try {
      await ElMessageBox.confirm(`${msg}\n确认代码可信并强制安装？`, 'AI 审核未通过', { type: 'warning' })
    } catch {
      Message.error('已取消安装')
      return
    }

    try {
      await upload(file, true)
    } catch (err: any) {
      Message.error(err?.response?.data?.message || '强制安装失败')
    }
  }
}

const toggle = async (row: any, next: string) => {
  try {
    await http.put(`${api}/${row.slug}`, { status: next })
    Message.success(next === 'disabled' ? '已停用' : '已启用')
    load()
  } catch (e: any) {
    Message.error(e?.response?.data?.message || '操作失败')
  }
}

const remove = async (row: any) => {
  try {
    await ElMessageBox.confirm(
      `卸载「${row.name}」会同时删除它同步出的功能与绑定关系，确定继续？`,
      '卸载插件',
      { type: 'warning' }
    )
  } catch {
    return
  }

  try {
    await http.delete(`${api}/${row.slug}`)
    Message.success('已卸载')
    load()
  } catch (e: any) {
    Message.error(e?.response?.data?.message || '卸载失败')
  }
}

const openDetail = async (row: any) => {
  detail.value = row
  detailVisible.value = true

  try {
    const { data } = await http.get(`${api}/${row.slug}`)
    detail.value = data.data || row
  } catch {
    // 拿不到详情就展示列表里已有的审核结果
  }
}

const openSettings = async () => {
  settingsVisible.value = true
  settingsLoading.value = true

  try {
    const { data } = await http.get(`${api}/settings`)
    settings.value = { ...settings.value, ...(data.data || {}), ai_api_key: '' }
  } finally {
    settingsLoading.value = false
  }
}

const saveSettings = async () => {
  settingsSaving.value = true

  try {
    await http.put(`${api}/settings`, settings.value)
    Message.success('设置已保存')
    settingsVisible.value = false
  } catch (e: any) {
    Message.error(e?.response?.data?.message || '保存失败')
  } finally {
    settingsSaving.value = false
  }
}

onMounted(load)
</script>

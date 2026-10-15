<template>
  <el-form label-width="140px" v-loading="loading" class="pr-4">
    <!-- 回调地址 -->
    <el-form-item :label="$t('feature.hook.url')">
      <template v-if="hook.token">
        <div class="flex items-center w-full">
          <el-input :model-value="hook.url" readonly class="flex-1" />
          <el-button class="ml-2" @click="copyUrl">{{ $t('feature.hook.copied') }}</el-button>
        </div>
      </template>
      <template v-else>
        <span class="text-xs text-gray-400">{{ $t('feature.hook.noHook') }}</span>
        <el-button class="ml-2" type="primary" size="small" @click="createHook">
          {{ $t('feature.hook.createHook') }}
        </el-button>
      </template>
    </el-form-item>

    <template v-if="hook.token">
      <!-- 基础 -->
      <el-divider content-position="left">{{ $t('feature.hook.basic') }}</el-divider>

      <el-form-item :label="$t('feature.field.name')">
        <el-input v-model="hook.name" clearable />
      </el-form-item>

      <el-form-item :label="$t('feature.field.enabled')">
        <el-switch v-model="hook.enabled" :active-value="1" :inactive-value="0" />
      </el-form-item>

      <el-form-item :label="$t('feature.hook.dedupField')">
        <el-input v-model="hook.dedup_field" clearable />
        <div class="text-xs text-gray-400">{{ $t('feature.hook.dedupHint') }}</div>
      </el-form-item>

      <!-- 验签 -->
      <el-divider content-position="left">{{ $t('feature.hook.sign') }}</el-divider>

      <el-form-item :label="$t('feature.hook.secret')">
        <el-input v-model="hook.secret" clearable />
      </el-form-item>
      <el-form-item :label="$t('feature.hook.signField')">
        <el-input v-model="hook.sign_field" clearable />
      </el-form-item>
      <el-form-item :label="$t('feature.hook.signAlgo')">
        <el-input v-model="hook.sign_algo" clearable />
      </el-form-item>
      <el-form-item :label="$t('feature.hook.checkTimestamp')">
        <el-switch v-model="hook.check_timestamp" :active-value="1" :inactive-value="0" />
      </el-form-item>

      <!-- 消息处理 -->
      <el-divider content-position="left">{{ $t('feature.transformer.label') }}</el-divider>

      <el-form-item :label="$t('feature.transformer.label')">
        <el-select
          v-model="transformer.current"
          :placeholder="$t('system.pleaseSelect')"
          :disabled="!transformer.can_edit"
          filterable
          clearable
          class="w-full"
        >
          <el-option v-for="o in transformer.options" :key="o.value" :label="o.label" :value="o.value">
            <span>{{ o.label }}</span>
            <span class="text-xs text-gray-400 ml-2">{{ o.description }}</span>
          </el-option>
        </el-select>
        <div class="text-xs text-gray-400">{{ $t('feature.transformer.hint') }}</div>
        <div v-if="transformer.missing" class="text-xs text-red-500">{{ $t('feature.transformer.missing') }}</div>
        <div v-if="!transformer.can_edit" class="text-xs text-red-500">
          {{ $t('feature.transformer.noPermission') }}
        </div>
      </el-form-item>

      <!-- 多机器人路由 -->
      <el-divider content-position="left">{{ $t('feature.hook.route') }}</el-divider>

      <el-form-item :label="$t('feature.hook.defaultBot')">
        <el-select v-model="hook.bot_id" :placeholder="$t('system.pleaseSelect')" filterable clearable class="w-full">
          <el-option v-for="b in bots" :key="b.value" :label="b.label" :value="b.value" />
        </el-select>
      </el-form-item>

      <el-form-item :label="$t('feature.hook.routeField')">
        <el-input v-model="hook.route_field" placeholder="out_merchant_id" clearable />
        <div class="text-xs text-gray-400">{{ $t('feature.hook.routeFieldHint') }}</div>
      </el-form-item>

      <el-form-item :label="$t('feature.hook.defaultChat')">
        <el-select v-model="hook.default_chat_id" :placeholder="$t('system.pleaseSelect')" filterable clearable
          class="w-full">
          <el-option v-for="g in groups" :key="g.value" :label="g.label" :value="g.value" />
        </el-select>
        <div class="text-xs text-gray-400">{{ $t('feature.hook.defaultChatHint') }}</div>
      </el-form-item>

      <!-- 路由表 -->
      <el-divider content-position="left">{{ $t('feature.hook.routes') }}</el-divider>

      <div class="text-xs text-gray-400 mb-2">{{ $t('feature.hook.routeHint') }}</div>

      <el-table :data="routes" size="small" border>
        <el-table-column :label="$t('feature.hook.routeValue')" min-width="160">
          <template #default="scope">
            <el-input v-model="scope.row.route_value" size="small" />
          </template>
        </el-table-column>
        <el-table-column :label="$t('feature.hook.bot')" min-width="180">
          <template #default="scope">
            <el-select v-model="scope.row.bot_id" :placeholder="$t('system.pleaseSelect')" filterable size="small"
              class="w-full">
              <el-option v-for="b in bots" :key="b.value" :label="b.label" :value="b.value" />
            </el-select>
          </template>
        </el-table-column>
        <el-table-column :label="$t('feature.hook.chat')" min-width="220">
          <template #default="scope">
            <el-select v-model="scope.row.chat_id" :placeholder="$t('system.pleaseSelect')" filterable size="small"
              class="w-full">
              <el-option v-for="g in groups" :key="g.value" :label="g.label" :value="g.value" />
            </el-select>
          </template>
        </el-table-column>
        <el-table-column :label="$t('feature.hook.remark')" min-width="140">
          <template #default="scope">
            <el-input v-model="scope.row.remark" size="small" />
          </template>
        </el-table-column>
        <el-table-column :label="$t('feature.field.enabled')" width="90">
          <template #default="scope">
            <el-switch v-model="scope.row.enabled" :active-value="1" :inactive-value="0" size="small" />
          </template>
        </el-table-column>
        <el-table-column :label="$t('system.operation')" width="90">
          <template #default="scope">
            <el-button type="danger" link size="small" @click="removeRoute(scope.$index)">
              {{ $t('feature.hook.deleteRoute') }}
            </el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-button class="mt-2" type="primary" plain size="small" @click="addRoute">
        {{ $t('feature.hook.addRoute') }}
      </el-button>
    </template>

    <div class="flex justify-end mt-4">
      <el-button type="primary" :loading="saving" @click="save">{{ $t('system.confirm') }}</el-button>
    </div>
  </el-form>
</template>

<script lang="ts" setup>
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import http from '@/support/http'
import Message from '@/support/message'

const props = defineProps({
  primary: { type: [String, Number], required: true },
  api: String,
})

const { t } = useI18n()

const loading = ref(false)
const saving = ref(false)

const bots = ref<any[]>([])
const groups = ref<any[]>([])
const routes = ref<any[]>([])

// 消息处理：转换器是全局代码资产，列表所有人可见，但「能不能改」由后端按归属链判定
const transformer = reactive<{ current: string; options: any[]; can_edit: boolean; missing: boolean }>({
  current: '',
  options: [],
  can_edit: true,
  missing: false,
})

// 回调入口：没有 token 就是还没建
const hook = reactive<any>({
  id: null,
  token: '',
  url: '',
  name: '',
  enabled: 1,
  dedup_field: '',
  secret: '',
  sign_field: '',
  sign_algo: '',
  check_timestamp: 0,
  bot_id: null,
  route_field: '',
  default_chat_id: '',
})

const loadOptions = async () => {
  try {
    const { data } = await http.get('telegram/features/options', { source: 'bots' })
    bots.value = data.data || []
  } catch {
    bots.value = []
  }

  try {
    const { data } = await http.get('telegram/features/options', { source: 'bot_groups' })
    groups.value = data.data || []
  } catch {
    groups.value = []
  }
}

// 每次打开都重新拉：转换器是扫描目录动态读的，开发者新增/删掉一个类，
// 重新打开功能就能看到最新的
const loadTransformer = async () => {
  try {
    const { data } = await http.get(`telegram/features/${props.primary}/transformer`)
    const row = data.data || {}

    transformer.current = row.current ?? ''
    transformer.options = row.options || []
    transformer.can_edit = !!row.can_edit
    transformer.missing = !!row.missing
  } catch {
    transformer.options = []
  }
}

const loadHook = async () => {
  loading.value = true

  try {
    const { data } = await http.get(`telegram/features/${props.primary}/hook`)
    const row = data.data

    if (row) {
      Object.assign(hook, {
        ...row,
        enabled: row.enabled ? 1 : 0,
        check_timestamp: row.check_timestamp ? 1 : 0,
        bot_id: row.bot_id ?? null,
      })
    }
  } catch {
    // 没建过就保持空表单
  } finally {
    loading.value = false
  }
}

const loadRoutes = async () => {
  if (!hook.id) {
    routes.value = []

    return
  }

  try {
    const { data } = await http.get(`telegram/features/${props.primary}/hook/routes`)
    routes.value = (data.data || []).map((r: any) => ({
      route_value: r.route_value,
      bot_id: r.bot_id,
      chat_id: String(r.chat_id),
      remark: r.remark || '',
      enabled: r.enabled ? 1 : 0,
    }))
  } catch {
    routes.value = []
  }
}

const createHook = async () => {
  saving.value = true

  try {
    const { data } = await http.put(`telegram/features/${props.primary}/hook`, {
      bot_id: hook.bot_id,
      route_field: hook.route_field,
      default_chat_id: hook.default_chat_id,
    })

    Object.assign(hook, {
      ...data.data,
      enabled: data.data.enabled ? 1 : 0,
      check_timestamp: data.data.check_timestamp ? 1 : 0,
    })

    Message.success(t('feature.hook.saved'))
  } catch (e: any) {
    Message.error(t('feature.hook.saveFailed') + (e?.message || ''))
  } finally {
    saving.value = false
  }
}

const addRoute = () => {
  routes.value.push({ route_value: '', bot_id: hook.bot_id ?? null, chat_id: '', remark: '', enabled: 1 })
}

const removeRoute = (index: number) => {
  routes.value.splice(index, 1)
}

const copyUrl = () => {
  navigator.clipboard?.writeText(hook.url)
  Message.success(t('feature.hook.copied'))
}

const save = async () => {
  saving.value = true

  try {
    // 先存回调入口（没有时会自动建，token 只在首次生成）
    const { data } = await http.put(`telegram/features/${props.primary}/hook`, {
      name: hook.name,
      enabled: !!hook.enabled,
      dedup_field: hook.dedup_field,
      secret: hook.secret,
      sign_field: hook.sign_field,
      sign_algo: hook.sign_algo,
      check_timestamp: !!hook.check_timestamp,
      bot_id: hook.bot_id,
      route_field: hook.route_field,
      default_chat_id: hook.default_chat_id,
    })

    Object.assign(hook, {
      ...data.data,
      enabled: data.data.enabled ? 1 : 0,
      check_timestamp: data.data.check_timestamp ? 1 : 0,
    })

    // 再存路由表：提交什么就是什么，未提交的会被删掉
    await http.post(`telegram/features/${props.primary}/hook/routes`, {
      routes: routes.value.map((r) => ({
        route_value: r.route_value,
        bot_id: r.bot_id,
        chat_id: r.chat_id,
        remark: r.remark,
        enabled: !!r.enabled,
      })),
    })

    // 再存消息处理：没有权限时后端会拒绝，这里先判一下省得弹一堆错
    if (transformer.can_edit) {
      await http.put(`telegram/features/${props.primary}/transformer`, {
        transformer: transformer.current,
      })

      await loadTransformer()
    }

    await loadRoutes()

    Message.success(t('feature.hook.saved'))
  } catch (e: any) {
    Message.error(t('feature.hook.saveFailed') + (e?.data?.message || e?.message || ''))
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await loadOptions()
  await loadHook()
  await loadTransformer()
  await loadRoutes()
})
</script>

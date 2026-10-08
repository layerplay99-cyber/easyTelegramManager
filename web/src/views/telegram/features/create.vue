<template>
  <el-form :model="formData" label-width="120px" ref="form" v-loading="loading" class="pr-4">
    <el-form-item label="功能名称" prop="name">
      <el-input v-model="(formData as any).name" name="name" clearable />
    </el-form-item>

    <!-- 执行器：决定这个功能是干什么的，选中后自动带出配置项 -->
    <el-form-item label="执行器" prop="driver">
      <el-select
        v-model="(formData as any).driver"
        placeholder="请选择执行器"
        filterable
        clearable
        class="w-full"
        :loading="driversLoading"
        @change="onDriverChange"
      >
        <el-option-group v-for="g in groupedDrivers" :key="g.group" :label="g.group">
          <el-option
            v-for="d in g.items"
            :key="d.key"
            :label="d.label"
            :value="d.key"
          >
            <span>{{ d.label }}</span>
            <span class="text-xs text-gray-400 ml-2">{{ d.key }}</span>
          </el-option>
        </el-option-group>
      </el-select>
      <div v-if="currentDriver" class="text-xs text-gray-500 mt-1">
        {{ currentDriver.label }} · 支持触发：{{ currentDriver.triggers.join(' / ') }}
      </div>
    </el-form-item>

    <el-form-item label="触发方式" prop="trigger">
      <el-select v-model="(formData as any).trigger" placeholder="请选择" clearable class="w-full">
        <el-option
          v-for="item in triggerOptions"
          :key="item.value"
          :label="item.label"
          :value="item.value"
        />
      </el-select>
    </el-form-item>

    <el-form-item label="功能分类" prop="category">
      <el-select v-model="(formData as any).category" placeholder="请选择" clearable class="w-full">
        <el-option v-for="item in category" :key="item.value" :label="item.label" :value="item.value" />
      </el-select>
    </el-form-item>

    <el-form-item label="功能描述" prop="description">
      <el-input v-model="(formData as any).description" type="textarea" :rows="2" clearable />
    </el-form-item>

    <el-form-item label="是否启用" prop="enabled">
      <el-select v-model="(formData as any).enabled" placeholder="请选择" clearable class="w-full">
        <el-option v-for="item in enabled" :key="item.value" :label="item.label" :value="item.value" />
      </el-select>
    </el-form-item>

    <!-- ============ 执行器配置：按 driver 的 schema 自动渲染，不再手写 JSON ============ -->
    <el-divider content-position="left">
      <span class="text-sm font-medium">执行器配置</span>
    </el-divider>

    <div v-if="!currentDriver" class="text-sm text-gray-400 mb-4">
      请先选择执行器，配置表单会自动出现
    </div>

    <template v-else-if="schema.length">
      <el-form-item v-for="f in schema" :key="f.key" :label="f.label">
        <!-- 文本 -->
        <el-input
          v-if="f.type === 'text'"
          v-model="config[f.key]"
          :placeholder="f.hint"
          clearable
          class="w-full"
        />

        <!-- 多行文本 / 模板 -->
        <el-input
          v-else-if="f.type === 'textarea' || f.type === 'template'"
          v-model="config[f.key]"
          type="textarea"
          :rows="3"
          :placeholder="f.hint"
          class="w-full"
        />

        <!-- 数字 -->
        <el-input-number
          v-else-if="f.type === 'number'"
          v-model="config[f.key]"
          class="w-full"
        />

        <!-- 开关 -->
        <el-switch v-else-if="f.type === 'switch'" v-model="config[f.key]" />

        <!-- 下拉 / 接口选择 -->
        <el-select
          v-else-if="f.type === 'select' || f.type === 'endpoint'"
          v-model="config[f.key]"
          :placeholder="f.hint"
          filterable
          clearable
          class="w-full"
        >
          <el-option
            v-for="o in optionsFor(f)"
            :key="o.value"
            :label="o.label"
            :value="o.value"
          />
        </el-select>

        <!-- 键值对（参数映射 / 保存字段） -->
        <div v-else-if="f.type === 'keyvalue'" class="w-full border border-gray-200 rounded p-3">
          <div class="text-xs text-gray-500 mb-2">{{ f.hint }}</div>
          <div v-for="(row, i) in kvRows(f.key)" :key="i" class="flex items-center space-x-2 mb-2">
            <el-input v-model="row.key" placeholder="字段名" class="flex-1" />
            <el-input v-model="row.value" placeholder="来源（可留空）" class="flex-1" />
            <el-button type="danger" plain size="small" @click="removeKvRow(f.key, i)">删除</el-button>
          </div>
          <el-button size="small" plain @click="addKvRow(f.key)">+ 添加一项</el-button>
        </div>

        <!-- 兜底 -->
        <el-input v-else v-model="config[f.key]" :placeholder="f.hint" clearable class="w-full" />

        <div v-if="f.required && !config[f.key]" class="text-xs text-red-400 mt-1">必填</div>
      </el-form-item>
    </template>

    <div v-else class="text-sm text-gray-400 mb-4">该执行器无需额外配置</div>

    <!-- ============ 命令配置：一个功能可挂多个命令，每个命令可声明多个参数 ============ -->
    <el-divider content-position="left">
      <span class="text-sm font-medium">命令配置</span>
    </el-divider>

    <div class="w-full border border-gray-200 rounded p-3 mb-2">
      <div v-if="!commands.length" class="text-sm text-gray-400 mb-2">暂无命令，点击下面按钮添加</div>

      <div
        v-for="(cmd, index) in commands"
        :key="index"
        class="border border-gray-100 rounded p-3 mb-3 bg-gray-50"
      >
        <div class="flex items-center space-x-2 mb-2">
          <el-input v-model="cmd.command" placeholder="命令名（不含斜杠）" class="flex-1">
            <template #prepend>/</template>
          </el-input>
          <el-button type="danger" plain size="small" @click="removeCommand(index)">删除命令</el-button>
        </div>

        <el-input v-model="cmd.description" placeholder="命令描述" class="mb-2" />
        <el-input v-model="cmd.usage" placeholder="用法示例，如 /ye" class="mb-2" />
        <el-input
          v-model="cmd.reply_template"
          type="textarea"
          :rows="2"
          placeholder="回复模板，如 余额：{{data.balance}} 元"
          class="mb-2"
        />

        <!-- 参数 -->
        <div class="text-xs text-gray-500 mb-1">参数（按顺序）：</div>
        <div
          v-for="(p, pi) in cmd.params"
          :key="pi"
          class="flex items-center space-x-2 mb-2"
        >
          <el-input v-model="p.name" placeholder="参数名" class="flex-1" />
          <el-input v-model="p.description" placeholder="说明" class="flex-1" />
          <el-input v-model="p.pattern" placeholder="正则（可选）" class="flex-1" />
          <el-checkbox v-model="p.required">必填</el-checkbox>
          <el-button type="danger" plain size="small" @click="removeParam(cmd, pi)">删除</el-button>
        </div>
        <el-button size="small" plain @click="addParam(cmd)">+ 添加参数</el-button>
      </div>

      <el-button type="primary" plain size="small" @click="addCommand">+ 添加命令</el-button>
    </div>

    <div class="flex justify-end mt-4">
      <el-button type="primary" @click="submitForm(form)">{{ $t('system.confirm') }}</el-button>
    </div>
  </el-form>
</template>

<script lang="ts" setup>
import { useCreate } from '@/composables/curd/useCreate'
import { useShow } from '@/composables/curd/useShow'
import { onMounted, ref, reactive, computed, watch } from 'vue'
import http from '@/support/http'
import { ElMessage } from 'element-plus'

const props = defineProps({
  primary: [String, Number],
  api: String,
})

const category = [
  { label: '系统', value: 'system' },
  { label: '客户端', value: 'custom' },
  { label: '机器人', value: 'bot' },
  { label: '真人', value: 'realMan' },
]

const enabled = [
  { label: '启用', value: 1 },
  { label: '禁用', value: 0 },
]

const triggerOptions = [
  { label: '斜杠命令', value: 'command' },
  { label: '按钮回调', value: 'callback_query' },
  { label: '三方推送', value: 'webhook' },
  { label: '手动/接口', value: 'manual' },
]

// ---------- 执行器 ----------
const drivers = ref<any[]>([])
const driversLoading = ref(false)

const groupedDrivers = computed(() => {
  const map: Record<string, any[]> = {}
  drivers.value.forEach((d) => {
    const g = d.group || '其它'
    ;(map[g] = map[g] || []).push(d)
  })
  return Object.entries(map).map(([group, items]) => ({ group, items }))
})

const currentDriver = computed(() => drivers.value.find((d) => d.key === (formData.value as any)?.driver) || null)
const schema = computed<any[]>(() => currentDriver.value?.config_schema || [])

// ---------- 配置值 ----------
const config = reactive<Record<string, any>>({})
// keyvalue 类型用行数组暂存：{ key, value }[]
const kv = reactive<Record<string, { key: string; value: string }[]>>({})

const kvRows = (fieldKey: string) => kv[fieldKey] || (kv[fieldKey] = [])
const addKvRow = (fieldKey: string) => kvRows(fieldKey).push({ key: '', value: '' })
const removeKvRow = (fieldKey: string, index: number) => kvRows(fieldKey).splice(index, 1)

// ---------- 命令 ----------
const commands = ref<any[]>([])

const addCommand = () => {
  commands.value.push({ command: '', description: '', usage: '', reply_template: '', params: [] })
}
const removeCommand = (index: number) => commands.value.splice(index, 1)
const addParam = (cmd: any) => {
  cmd.params = cmd.params || []
  cmd.params.push({ name: '', description: '', pattern: '', required: false })
}
const removeParam = (cmd: any, index: number) => cmd.params.splice(index, 1)

// ---------- 下拉选项 ----------
const dynamicOptions = ref<Record<string, any[]>>({})

/**
 * 取字段选项：优先用后端返回的 options（静态来源），
 * 动态来源（三方配置/接口）按 source 单独拉取一次。
 */
const optionsFor = (field: any) => {
  if (field.options && field.options.length) return field.options
  return dynamicOptions.value[field.source] || []
}

/**
 * 动态来源（三方配置/接口/群）按 source 向后端拉一次列表
 */
const loadDynamicOptions = async (sources: string[]) => {
  const uniq = [...new Set(sources.filter(Boolean))]

  await Promise.all(uniq.map(async (source) => {
    if (dynamicOptions.value[source]) return

    try {
      const { data } = await http.get('telegram/features/options', { source })
      dynamicOptions.value[source] = data.data || []
    } catch {
      dynamicOptions.value[source] = []
    }
  }))
}

// ---------- 表单 ----------
const { formData, form, loading, submitForm: originalSubmitForm, close } = useCreate(props.api, props.primary)

let showResult: any = null

if (props.primary) {
  showResult = useShow(props.api, props.primary, formData)

  // 用useShow 提供的 afterShow 钩子拿数据。
  // 之前用 watch(loading) 判断，但 loading 初值就是 true，
  // 若钩子注册时机晚于数据返回则 watch 永远不触发 → 表单空白/三条功能显示相同。
  showResult.afterShow.value = () => {
  // formData 已由 useShow 填充，这里只做派生处理
    rawConfig = (formData.value as any)?.config
  parseConfig(rawConfig)
    loadCommands()
  }
}

/**
 * 解析 features.config（可能是 JSON 字符串或对象）
 */
const parseConfig = (raw: any) => {
  let parsed: any = raw

  if (typeof raw === 'string') {
    try {
      parsed = JSON.parse(raw || '{}')
    } catch {
    parsed = {}
    }
  }

  parsed = parsed && typeof parsed === 'object' ? parsed : {}

  // 先清空，避免上一个功能的残留字段混进来
  Object.keys(config).forEach((k) => delete config[k])
  Object.keys(kv).forEach((k) => delete kv[k])

  // 套用驱动 schema 的默认值，再写入实际值
  schema.value.forEach((f) => {
    if (f.type === 'switch') config[f.key] = f.default ?? false
    else if (f.default !== undefined && f.default !== null) config[f.key] = f.default
    else config[f.key] = f.type === 'keyvalue' ? {} : ''
})

  Object.assign(config, parsed)

  // keyvalue 字段回填成行
  schema.value
    .filter((f) => f.type === 'keyvalue')
    .forEach((f) => {
      const val = parsed[f.key]
      kv[f.key] = Array.isArray(val)
        ? val.map((v: any) => ({ key: String(v), value: '' }))
     : Object.entries(val || {}).map(([k, v]) => ({ key: String(k), value: String(v ?? '') }))
    })
}

// 执行器切换或数据加载后，拉取其 schema 里的动态下拉选项
watch(
  () => schema.value.map((f: any) => f.source).filter(Boolean),
  (sources) => {
    if (sources.length) loadDynamicOptions(sources as string[])
  },
  { immediate: true, deep: true }
)

const onDriverChange = () => {
  // 切换执行器：清空旧配置，套用默认值
  Object.keys(config).forEach((k) => delete config[k])
  Object.keys(kv).forEach((k) => delete kv[k])

  schema.value.forEach((f) => {
    if (f.type === 'switch') config[f.key] = f.default ?? false
    else if (f.default !== undefined && f.default !== null) config[f.key] = f.default
    else config[f.key] = f.type === 'keyvalue' ? {} : ''
  })
}

// ---------- 命令加载/保存 ----------
const loadCommands = async () => {
  if (!props.primary) return
  try {
    const { data } = await http.get(`telegram/features/${props.primary}/commands`)
    commands.value = (data.data || []).map((c: any) => ({
      command: c.command,
      description: c.description || '',
      usage: c.usage || '',
      reply_template: c.reply_template || '',
      params: c.params || [],
    }))
  } catch {
    commands.value = []
  }
}

const saveCommands = async (featureId: any) => {
  if (!featureId) return
  const payload = commands.value
    .filter((c) => c.command)
    .map((c) => ({
      command: String(c.command).replace(/^\//, '').toLowerCase(),
      description: c.description,
      usage: c.usage,
      reply_template: c.reply_template,
      params: (c.params || []).filter((p: any) => p.name),
    }))

  if (!payload.length) return

  try {
    await http.post(`telegram/features/${featureId}/commands`, { commands: payload })
  } catch (e: any) {
    ElMessage.warning('功能已保存，但命令保存失败：' + (e?.message || ''))
  }
}

const submitForm = async (formEl: any) => {
  // keyvalue 行 → 对象
  schema.value.filter((f) => f.type === 'keyvalue').forEach((f) => {
    const obj: Record<string, string> = {}
    ;(kv[f.key] || []).forEach((row) => {
      if (row.key) obj[row.key] = row.value || ''
    })
    config[f.key] = Object.keys(obj).length ? obj : undefined
  })

  const clean: Record<string, any> = {}
  Object.keys(config).forEach((k) => {
    const v = config[k]
    if (v !== '' && v !== null && v !== undefined) clean[k] = v
  })

  if (formData.value) {
    ;(formData.value as any).config = clean
  }

  await originalSubmitForm(formEl)

  // 保存成功后写入命令
  const id = props.primary || (formData.value as any)?.id
  await saveCommands(id)
}

// ---------- 初始化 ----------
// 保存原始配置，待驱动列表就绪后重新解析
let rawConfig: any = null

const loadDrivers = async () => {
  driversLoading.value = true
  try {
    const { data } = await http.get('telegram/features/drivers')
 drivers.value = data.data || []
    // 驱动就绪后重解析一次：此前若在 drivers 未到时解析，schema 为空会导致配置渲染不出来
    if (props.primary && rawConfig !== null) parseConfig(rawConfig)
  } catch (e) {
    console.error('加载执行器失败:', e)
  } finally {
    driversLoading.value = false
  }
}

const emit = defineEmits(['close'])

onMounted(() => {
  close(() => emit('close'))
  loadDrivers()

  if (!props.primary && formData.value) {
    // 新建时不再硬塞默认值：执行器需要用户显式选择，
    // 否则会出现「有默认 driver 但下拉里没对应选项」的困惑。
    // 只给触发方式一个常用默认值。
    ;(formData.value as any).driver = ''
    ;(formData.value as any).trigger = 'command'
  }
})
</script>
<template>
    <el-form :model="formData" label-width="120px" ref="form" v-loading="loading" class="pr-4">
        <el-form-item label="Bot-ID" prop="bot_id">
            <el-input v-model="fd.bot_id" name="bot_id" :readonly="true" clearable />
        </el-form-item>
        <el-form-item :label="$t('group.name')" prop="name">
            <el-input v-model="fd.name" name="name" :readonly="true" clearable />
        </el-form-item>
        <el-form-item :label="$t('group.chatId')" prop="chat_id">
            <el-input v-model="fd.chat_id" name="chat_id" :readonly="true" clearable />
        </el-form-item>

        <!-- 语种配置：识图 OCR 按它选引擎，群回复 / 欢迎语的双语也按它翻译 -->
        <el-form-item label="语种配置" prop="settings">
            <el-select v-model="groupLanguage" placeholder="默认（中文）" clearable filterable class="w-full">
                <el-option v-for="o in languages" :key="o.value" :label="o.label" :value="o.value" />
            </el-select>
            <div class="text-xs text-gray-400 mt-1">
                不选择就用默认语种（中文）。可选项来自配置，加语种只需改 config/telegram.php。
            </div>
        </el-form-item>

        <el-divider content-position="left">{{ $t('group.configTitle') }}</el-divider>

        <el-form-item :label="$t('group.merchantId')" prop="mid">
            <el-input v-model="groupConfig.mid" :placeholder="$t('group.merchantIdPlaceholder')" />
            <div class="text-xs text-gray-400 mt-1">
                留空时功能取 /bd 绑定的商户号；填了就以这里为准（它是这个群的默认商户号）。
            </div>
        </el-form-item>

        <el-form-item :label="$t('group.welcome')" prop="welcome">
            <el-input
                v-model="groupConfig.welcome"
                :placeholder="$t('group.welcomePlaceholder')"
                type="textarea"
                :rows="3"
            />
            <div class="text-xs text-gray-400 mt-1">{{ $t('group.welcomeTip') }}</div>
        </el-form-item>

        <el-form-item :label="$t('group.autoReply')" prop="autoReply">
            <div class="space-y-2 w-full">
                <div v-for="(item, index) in groupConfig.autoReply" :key="index" class="flex gap-2">
                    <el-input
                        v-model="item.key"
                        :placeholder="$t('group.keyword')"
                        style="flex: 1"
                    />
                    <el-input
                        v-model="item.value"
                        :placeholder="$t('group.replyContent')"
                        style="flex: 2"
                    />
                    <el-button type="danger" link @click="removeAutoReply(index)">
                        {{ $t('system.delete') }}
                    </el-button>
                </div>
                <el-button type="primary" link @click="addAutoReply">
                    {{ $t('group.addAutoReply') }}
                </el-button>
            </div>
        </el-form-item>

        <div class="flex justify-end">
            <el-button type="primary" :loading="saving" @click="submitAll(form)">
                {{ $t('system.confirm') }}
            </el-button>
        </div>
    </el-form>
</template>

<script lang="ts" setup>
import { useCreate } from '@/composables/curd/useCreate'
import { useShow } from '@/composables/curd/useShow'
import http from '@/support/http'
import Message from '@/support/message'
import { useGroup } from '@/stores/modules/telegram/group'
import { computed, onMounted, ref, watch } from 'vue'

const props = defineProps({
    primary: [String, Number],
    api: String,
})

const { formData, form, loading, submitForm, close } = useCreate(props.api, props.primary)

// useCreate 的 formData 是 ref<object>，模板里直接取字段过不了类型检查
const fd = computed(() => formData.value as any)

if (props.primary) {
    useShow(props.api, props.primary, formData)
}

const emit = defineEmits(['close'])
onMounted(() => {
    close(() => emit('close'))
})

// ---------- 语种配置 ----------
const languages = ref<any[]>([])
const groupLanguage = ref<string>('')

const loadLanguages = async () => {
    try {
        // 复用现成的 options 接口，不加新路由（新路由在开了 route:cache 的环境要重新 cache 才生效）
        //
        // ⚠️ 第二个参数就是 axios 的 params 本身，Request.get() 内部已经包了一层
        //    { params }（见 support/request.ts）。这里再写成 { params: {...} } 会变成
        //    ?params[source]=group_languages，后端拿不到 source → 走 default 返回空数组。
        const { data } = await http.get('telegram/features/options', { source: 'group_languages' })
        languages.value = unwrapList(data)
    } catch {
        languages.value = []
    }
}

/**
 * 后台接口统一是一层 {code, message, data}（由 App\Listeners\DashboardResponseListener 保证）。
 *
 * 这里仍兼容历史的两层结构（控制器自己返回信封时旧版本会被再包一层），
 * 免得后端一调整前端就静默失效：
 * 只剥一层拿到的是里层那个对象（不是数组），el-select 渲染不出来，
 * 表现就是「接口返回了数据、下拉还是空的」。
 */
const unwrapList = (res: any): any[] => {
    const payload = res?.data

    if (Array.isArray(payload)) {
        return payload
    }

    return Array.isArray(payload?.data) ? payload.data : []
}

// show 是异步的，settings 可能晚一步到，用 watch 回填
watch(
    () => (formData.value as any)?.settings,
    (settings) => {
        groupLanguage.value = settings?.language ? String(settings.language) : ''
    },
    { immediate: true, deep: true }
)

// ---------- 群配置（商户ID / 欢迎语 / 自动回复）----------
// 原先是「群列表 → 配置」另一个弹窗，跟「编辑」分在两处，
// 现在合并到这里：一个群的所有设置只在一个地方改。
const configId = ref<number | null>(null)

const groupConfig = ref<{
    mid: string
    welcome: string
    autoReply: Array<{ key: string; value: string }>
}>({
    mid: '',
    welcome: '',
    autoReply: [],
})

const loadConfig = async () => {
    if (!props.primary) {
        return
    }

    try {
        const config = await useGroup().getGroupConfig(Number(props.primary))

        configId.value = config?.id ?? null
        groupConfig.value = {
            mid: config?.mid ?? '',
            welcome: config?.welcome ?? '',
            autoReply: config?.autoReply
                ? Object.entries(config.autoReply).map(([key, value]) => ({ key, value: String(value) }))
                : [],
        }
    } catch {
        configId.value = null
        groupConfig.value = { mid: '', welcome: '', autoReply: [] }
    }
}

const addAutoReply = () => {
    groupConfig.value.autoReply.push({ key: '', value: '' })
}

const removeAutoReply = (index: number) => {
    groupConfig.value.autoReply.splice(index, 1)
}

// ---------- 保存 ----------
const saving = ref(false)

const submitAll = async (formEl: any) => {
    const fd = formData.value as any

    fd.settings = { ...(fd.settings || {}), language: groupLanguage.value || '' }

    // 没有 primary 是「新增」，走原来的 create 流程（列表目前没有新增入口，保留兜底）
    if (!props.primary) {
        submitForm(formEl)

        return
    }

    saving.value = true

    try {
        // 1) 群信息 + 语种
        await http.put(`${props.api}/${props.primary}`, fd)

        // 2) 群配置：商户ID / 欢迎语 / 自动回复
        const autoReply = groupConfig.value.autoReply.reduce((acc, item) => {
            if (item.key && item.value) {
                acc[item.key] = item.value
            }

            return acc
        }, {} as Record<string, string>)

        await useGroup().createOrUpdateGroupConfig(configId.value || 0, {
            group_id: Number(props.primary),
            mid: groupConfig.value.mid,
            welcome: groupConfig.value.welcome,
            autoReply,
        } as any)

        emit('close')
    } catch (error: any) {
        Message.error(error?.message || '保存失败')
    } finally {
        saving.value = false
    }
}

loadLanguages()
loadConfig()
</script>

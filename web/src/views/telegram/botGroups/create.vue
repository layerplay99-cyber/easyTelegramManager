<template>
    <el-form :model="formData" label-width="120px" ref="form" v-loading="loading" class="pr-4">
        <el-form-item label="Bot-ID" prop="bot_id">
            <el-input v-model="formData.bot_id" name="bot_id" :readonly="true" clearable />
        </el-form-item>
        <el-form-item :label="$t('group.name')" prop="name">
            <el-input v-model="formData.name" name="name" :readonly="true" clearable />
        </el-form-item>
        <el-form-item :label="$t('group.chatId')" prop="chat_id">
            <el-input v-model="formData.chat_id" name="chat_id" :readonly="true" clearable />
        </el-form-item>

        <!-- 语种配置：识图按这个语种走对应的 OCR 逻辑，群回复/欢迎语的双语也按它翻译 -->
        <el-form-item label="语种配置" prop="settings">
            <el-select v-model="groupLanguage" placeholder="默认（中文）" clearable filterable class="w-full">
                <el-option v-for="o in languages" :key="o.value" :label="o.label" :value="o.value" />
            </el-select>
            <div class="text-xs text-gray-400 mt-1">
                不选择就用默认语种（中文）。可选项来自配置，加语种只需改 config/telegram.php。
            </div>
        </el-form-item>

        <div class="flex justify-end">
            <el-button type="primary" @click="submitWithLanguage(form)">{{ $t('system.confirm') }}</el-button>
        </div>
    </el-form>
</template>

<script lang="ts" setup>
import { useCreate } from '@/composables/curd/useCreate'
import { useShow } from '@/composables/curd/useShow'
import http from '@/support/http'
import { onMounted, ref, watch } from 'vue'

const props = defineProps({
    primary: [String, Number],
    api: String,
})

const { formData, form, loading, submitForm, close } = useCreate(props.api, props.primary)

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

/**
 * 后台接口统一是一层 {code, message, data}（由 App\Listeners\DashboardResponseListener 保证）。
 *
 * 这里仍兼容历史的两层结构（控制器自己返回信封时旧版本会被再包一层），
 * 免得后端一调整前端就静默失效：
 * 只剥一层拿到的是里层那个对象（不是数组），el-select 渲染不出来，
 * 表现就是「接口返回了数据、下拉还是空的」。
 * 与 features/create.vue 的 unwrapList 同款处理。
 */
const unwrapList = (res: any): any[] => {
    const payload = res?.data

    if (Array.isArray(payload)) {
        return payload
    }

    return Array.isArray(payload?.data) ? payload.data : []
}

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
loadLanguages()

// show 是异步的，settings 可能晚一步到，用 watch 回填
watch(
    () => (formData.value as any)?.settings,
    (settings) => {
        groupLanguage.value = settings?.language ? String(settings.language) : ''
    },
    { immediate: true, deep: true }
)

const submitWithLanguage = (formEl: any) => {
    const fd = formData.value as any
    fd.settings = { ...(fd.settings || {}), language: groupLanguage.value || '' }
    submitForm(formEl)
}
</script>

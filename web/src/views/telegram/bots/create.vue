<template>
    <el-form :model="formData" label-width="120px" ref="form" v-loading="loading" class="pr-4">
        <el-form-item label="Api_Token" prop="api_token">
            <el-input v-model="formData.api_token" name="api_token" clearable />
        </el-form-item>
        <el-form-item label="Bot用户名" prop="username">
            <el-input v-model="formData.username" name="username" clearable />
        </el-form-item>
        <el-form-item label="Url_Token" prop="url_token">
            <el-input v-model="formData.url_token" name="url_token" clearable />
        </el-form-item>
        <el-form-item label="WebHookUrl" prop="webhook_url">
            <el-input v-model="formData.webhook_url" name="webhook_url" clearable />
        </el-form-item>
        <el-form-item label="Bot描述" prop="description">
            <el-input v-model="formData.description" name="description" clearable />
        </el-form-item>
        <el-form-item v-show="false" label="状态" prop="enabled">
            <el-select v-model="formData.enabled" placeholder="请选择" clearable>
                <el-option v-for="item in enabled" :key="item.value" :label="item.label" :value="item.value" />
            </el-select>
        </el-form-item>
        <div class="flex justify-end">
            <el-button type="primary" @click="submitForm(form)">{{ $t('system.confirm') }}</el-button>
        </div>
    </el-form>
</template>

<script lang="ts" setup>
import { useCreate } from '@/composables/curd/useCreate'
import { useShow } from '@/composables/curd/useShow'
import { onMounted, ref } from 'vue'

const props = defineProps({
    primary: [String, Number],
    api: String,
})

const enabled = ref([
    { value: 1, label: '启用' },
    { value: 0, label: '禁用' }
])

const { formData, form, loading, submitForm, close } = useCreate(props.api, props.primary)

if (props.primary) {
    useShow(props.api, props.primary, formData)
}

const emit = defineEmits(['close'])
onMounted(() => {
    close(() => emit('close'))
})
</script>

<template>
    <el-form :model="formData" label-width="120px" ref="form" v-loading="loading" class="pr-4">
        <el-form-item label="Bot-ID" prop="bot_id">
            <el-input v-model="formData.bot_id" name="bot_id" :readonly="true" clearable />
        </el-form-item>
        <el-form-item label="群名称" prop="name">
            <el-input v-model="formData.name" name="name" :readonly="true" clearable />
        </el-form-item>
        <el-form-item label="群聊ID" prop="chat_id">
            <el-input v-model="formData.chat_id" name="chat_id" :readonly="true" clearable />
        </el-form-item>
        <!-- <el-form-item label="状态" prop="status">
            <el-select v-model="formData.status" placeholder="请选择" clearable multiple>
                <el-option v-for="item in options" :key="item.value" :label="item.label" :value="item.value" />
            </el-select>
        </el-form-item> -->
        <div class="flex justify-end">
            <el-button type="primary" @click="submitForm(form)">{{ $t('system.confirm') }}</el-button>
        </div>
    </el-form>
</template>

<script lang="ts" setup>
import { useCreate } from '@/composables/curd/useCreate'
import { useShow } from '@/composables/curd/useShow'
import { onMounted } from 'vue'

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
</script>

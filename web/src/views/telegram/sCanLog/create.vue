<template>
    <el-form :model="formData" label-width="120px" ref="form" v-loading="loading" class="pr-4">
        <el-form-item label="被扫电话" prop="phone">
            <el-input v-model="formData.phone" name="phone" clearable />
        </el-form-item>
        <el-form-item label="N天内活跃" prop="days">
            <el-input-number v-model="formData.days" name="days" :min="1" />
        </el-form-item>
        <el-form-item label="昵称" prop="username">
            <el-input v-model="formData.username" name="username" clearable />
        </el-form-item>
        <el-form-item label="简介" prop="bio">
            <el-input v-model="formData.bio" name="bio" clearable />
        </el-form-item>
        <el-form-item label="用户头像" prop="avatar_path">
            <el-input v-model="formData.avatar_path" name="avatar_path" clearable />
        </el-form-item>
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

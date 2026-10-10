<template>
    <el-form :model="formData" label-width="120px" ref="form" v-loading="loading" class="pr-4">
        <el-form-item label="限额名称" prop="name">
            <el-input v-model="formData.name" name="name" clearable />
        </el-form-item>
        <el-form-item label="类型" prop="type">
            <el-select v-model="formData.type" placeholder="请选择">
                <el-option label="充值" value="recharge" />
                <el-option label="提现" value="withdraw" />
            </el-select>
        </el-form-item>
        <el-form-item label="会员等级" prop="level">
            <el-input v-model="formData.level" name="level" placeholder="default" clearable />
        </el-form-item>
        <el-form-item label="币种" prop="currency">
            <el-input v-model="formData.currency" name="currency" placeholder="CNY / USDT" clearable />
        </el-form-item>
        <el-form-item label="单笔最小" prop="min_amount">
            <el-input-number v-model="formData.min_amount" :min="0" :precision="2" />
        </el-form-item>
        <el-form-item label="单笔最大" prop="max_amount">
            <el-input-number v-model="formData.max_amount" :min="0" :precision="2" />
        </el-form-item>
        <el-form-item label="日累计额" prop="daily_amount">
            <el-input-number v-model="formData.daily_amount" :min="0" :precision="2" />
        </el-form-item>
        <el-form-item label="月累计额" prop="monthly_amount">
            <el-input-number v-model="formData.monthly_amount" :min="0" :precision="2" />
        </el-form-item>
        <el-form-item label="日笔数" prop="daily_count">
            <el-input-number v-model="formData.daily_count" :min="0" />
        </el-form-item>
        <el-form-item label="月笔数" prop="monthly_count">
            <el-input-number v-model="formData.monthly_count" :min="0" />
        </el-form-item>
        <el-form-item label="状态" prop="status">
            <el-select v-model="formData.status">
                <el-option label="启用" :value="1" />
                <el-option label="停用" :value="0" />
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

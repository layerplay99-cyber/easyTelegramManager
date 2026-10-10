<template>
    <el-form :model="formData" label-width="120px" ref="form" v-loading="loading" class="pr-4">
        <el-form-item label="通道名称" prop="name">
            <el-input v-model="formData.name" name="name" clearable />
        </el-form-item>
        <el-form-item label="编码" prop="code">
            <el-input v-model="formData.code" name="code" clearable />
        </el-form-item>
        <el-form-item label="类型" prop="type">
            <el-select v-model="formData.type" placeholder="请选择">
                <el-option label="充值" value="recharge" />
                <el-option label="提现" value="withdraw" />
                <el-option label="充值+提现" value="both" />
            </el-select>
        </el-form-item>
        <el-form-item label="币种" prop="currency">
            <el-input v-model="formData.currency" name="currency" clearable />
        </el-form-item>
        <el-form-item label="支付方式" prop="method">
            <el-input v-model="formData.method" name="method" placeholder="如 bank / usdt_trc20" clearable />
        </el-form-item>
        <el-form-item label="提单API" prop="submit_api">
            <el-input v-model="formData.submit_api" name="submit_api" clearable />
        </el-form-item>
        <el-form-item label="查单API" prop="query_api">
            <el-input v-model="formData.query_api" name="query_api" clearable />
        </el-form-item>
        <el-form-item label="费率(%)" prop="fee_rate">
            <el-input-number v-model="formData.fee_rate" :min="0" :precision="2" />
        </el-form-item>
        <el-form-item label="固定手续费" prop="fixed_fee">
            <el-input-number v-model="formData.fixed_fee" :min="0" :precision="2" />
        </el-form-item>
        <el-form-item label="单笔最小" prop="min_amount">
            <el-input-number v-model="formData.min_amount" :min="0" :precision="2" />
        </el-form-item>
        <el-form-item label="单笔最大" prop="max_amount">
            <el-input-number v-model="formData.max_amount" :min="0" :precision="2" />
        </el-form-item>
        <el-form-item label="每日限额" prop="daily_limit">
            <el-input-number v-model="formData.daily_limit" :min="0" :precision="2" />
        </el-form-item>
        <el-form-item label="每日笔数" prop="daily_count_limit">
            <el-input-number v-model="formData.daily_count_limit" :min="0" />
        </el-form-item>
        <el-form-item label="商户号" prop="merchant_id">
            <el-input v-model="formData.merchant_id" name="merchant_id" clearable />
        </el-form-item>
        <el-form-item label="密钥" prop="secret_key">
            <el-input v-model="formData.secret_key" name="secret_key" clearable />
        </el-form-item>
        <el-form-item label="状态" prop="status">
            <el-select v-model="formData.status">
                <el-option label="启用" :value="1" />
                <el-option label="禁用" :value="0" />
                <el-option label="维护" :value="2" />
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

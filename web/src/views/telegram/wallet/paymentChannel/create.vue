<template>
    <el-form :model="formData" label-width="120px" ref="form" v-loading="loading" class="pr-4">
        <el-form-item :label="$t('wallet.channelName')" prop="name">
            <el-input v-model="formData.name" name="name" clearable />
        </el-form-item>
        <el-form-item :label="$t('wallet.code')" prop="code">
            <el-input v-model="formData.code" name="code" clearable />
        </el-form-item>
        <el-form-item :label="$t('wallet.type')" prop="type">
            <el-select v-model="formData.type" :placeholder="$t('system.pleaseSelect')">
                <el-option :label="$t('wallet.recharge')" value="recharge" />
                <el-option :label="$t('wallet.withdraw')" value="withdraw" />
                <el-option :label="$t('wallet.rechargeAndWithdraw')" value="both" />
            </el-select>
        </el-form-item>
        <el-form-item :label="$t('wallet.currency')" prop="currency">
            <el-input v-model="formData.currency" name="currency" clearable />
        </el-form-item>
        <el-form-item :label="$t('wallet.paymentMethod')" prop="method">
            <el-input v-model="formData.method" name="method" placeholder="bank / usdt_trc20" clearable />
        </el-form-item>
        <el-form-item :label="$t('wallet.submitApi')" prop="submit_api">
            <el-input v-model="formData.submit_api" name="submit_api" clearable />
        </el-form-item>
        <el-form-item :label="$t('wallet.queryApi')" prop="query_api">
            <el-input v-model="formData.query_api" name="query_api" clearable />
        </el-form-item>
        <el-form-item :label="$t('wallet.feeRate')" prop="fee_rate">
            <el-input-number v-model="formData.fee_rate" :min="0" :precision="2" />
        </el-form-item>
        <el-form-item :label="$t('wallet.fixedFee')" prop="fixed_fee">
            <el-input-number v-model="formData.fixed_fee" :min="0" :precision="2" />
        </el-form-item>
        <el-form-item :label="$t('wallet.minAmount')" prop="min_amount">
            <el-input-number v-model="formData.min_amount" :min="0" :precision="2" />
        </el-form-item>
        <el-form-item :label="$t('wallet.maxAmount')" prop="max_amount">
            <el-input-number v-model="formData.max_amount" :min="0" :precision="2" />
        </el-form-item>
        <el-form-item :label="$t('wallet.dailyLimit')" prop="daily_limit">
            <el-input-number v-model="formData.daily_limit" :min="0" :precision="2" />
        </el-form-item>
        <el-form-item :label="$t('wallet.dailyCountLimit')" prop="daily_count_limit">
            <el-input-number v-model="formData.daily_count_limit" :min="0" />
        </el-form-item>
        <el-form-item :label="$t('wallet.merchantId')" prop="merchant_id">
            <el-input v-model="formData.merchant_id" name="merchant_id" clearable />
        </el-form-item>
        <el-form-item :label="$t('wallet.secretKey')" prop="secret_key">
            <el-input v-model="formData.secret_key" name="secret_key" clearable />
        </el-form-item>
        <el-form-item :label="$t('system.status')" prop="status">
            <el-select v-model="formData.status">
                <el-option :label="$t('system.enabled')" :value="1" />
                <el-option :label="$t('system.disabled')" :value="0" />
                <el-option :label="$t('wallet.maintenance')" :value="2" />
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

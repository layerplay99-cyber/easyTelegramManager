<template>
    <el-form :model="formData" label-width="120px" ref="form" v-loading="loading" class="pr-4">
        <el-form-item :label="$t('wallet.limitName')" prop="name">
            <el-input v-model="formData.name" name="name" clearable />
        </el-form-item>
        <el-form-item :label="$t('wallet.type')" prop="type">
            <el-select v-model="formData.type" :placeholder="$t('system.pleaseSelect')">
                <el-option :label="$t('wallet.recharge')" value="recharge" />
                <el-option :label="$t('wallet.withdraw')" value="withdraw" />
            </el-select>
        </el-form-item>
        <el-form-item :label="$t('wallet.memberLevel')" prop="level">
            <el-input v-model="formData.level" name="level" placeholder="default" clearable />
        </el-form-item>
        <el-form-item :label="$t('wallet.currency')" prop="currency">
            <el-input v-model="formData.currency" name="currency" placeholder="CNY / USDT" clearable />
        </el-form-item>
        <el-form-item :label="$t('wallet.minAmount')" prop="min_amount">
            <el-input-number v-model="formData.min_amount" :min="0" :precision="2" />
        </el-form-item>
        <el-form-item :label="$t('wallet.maxAmount')" prop="max_amount">
            <el-input-number v-model="formData.max_amount" :min="0" :precision="2" />
        </el-form-item>
        <el-form-item :label="$t('wallet.dailyAmount')" prop="daily_amount">
            <el-input-number v-model="formData.daily_amount" :min="0" :precision="2" />
        </el-form-item>
        <el-form-item :label="$t('wallet.monthlyAmount')" prop="monthly_amount">
            <el-input-number v-model="formData.monthly_amount" :min="0" :precision="2" />
        </el-form-item>
        <el-form-item :label="$t('wallet.dailyCount')" prop="daily_count">
            <el-input-number v-model="formData.daily_count" :min="0" />
        </el-form-item>
        <el-form-item :label="$t('wallet.monthlyCount')" prop="monthly_count">
            <el-input-number v-model="formData.monthly_count" :min="0" />
        </el-form-item>
        <el-form-item :label="$t('system.status')" prop="status">
            <el-select v-model="formData.status">
                <el-option :label="$t('system.enabled')" :value="1" />
                <el-option :label="$t('system.disabled')" :value="0" />
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

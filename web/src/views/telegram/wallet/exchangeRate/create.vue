<template>
    <el-form :model="formData" label-width="120px" ref="form" v-loading="loading" class="pr-4">
        <el-form-item label="源币种" prop="from_currency">
            <el-input v-model="formData.from_currency" name="from_currency" clearable />
        </el-form-item>
        <el-form-item label="目标币种" prop="to_currency">
            <el-input v-model="formData.to_currency" name="to_currency" clearable />
        </el-form-item>
        <el-form-item label="基准汇率" prop="rate">
            <el-input-number v-model="formData.rate" :min="0" :precision="8" />
        </el-form-item>
        <el-form-item label="买入价" prop="buy_rate">
            <el-input-number v-model="formData.buy_rate" :min="0" :precision="8" />
        </el-form-item>
        <el-form-item label="卖出价" prop="sell_rate">
            <el-input-number v-model="formData.sell_rate" :min="0" :precision="8" />
        </el-form-item>
        <el-form-item label="来源" prop="source">
            <el-input v-model="formData.source" name="source" placeholder="manual / api" clearable />
        </el-form-item>
        <el-form-item label="自动更新" prop="auto_update">
            <el-switch v-model="formData.auto_update" :active-value="1" :inactive-value="0" />
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

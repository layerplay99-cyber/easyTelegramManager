<template>
    <el-form :model="formData" label-width="120px" ref="form" v-loading="loading" class="pr-4">
        <el-form-item :label="$t('wallet.ruleName')" prop="name">
            <el-input v-model="formData.name" name="name" clearable />
        </el-form-item>
        <el-form-item :label="$t('wallet.ruleCode')" prop="code">
            <el-input v-model="formData.code" name="code" :placeholder="$t('wallet.ruleCodePlaceholder')" clearable />
        </el-form-item>
        <el-form-item :label="$t('wallet.scene')" prop="type">
            <el-select v-model="formData.type" :placeholder="$t('system.pleaseSelect')">
                <el-option :label="$t('wallet.recharge')" value="recharge" />
                <el-option :label="$t('wallet.withdraw')" value="withdraw" />
                <el-option :label="$t('wallet.transfer')" value="transfer" />
            </el-select>
        </el-form-item>
        <el-form-item :label="$t('wallet.triggerAction')" prop="action">
            <el-select v-model="formData.action" :placeholder="$t('system.pleaseSelect')">
                <el-option :label="$t('wallet.actionReject')" value="reject" />
                <el-option :label="$t('wallet.actionManualAudit')" value="manual_audit" />
                <el-option :label="$t('wallet.actionFreeze')" value="freeze" />
                <el-option :label="$t('wallet.actionNotify')" value="notify" />
            </el-select>
        </el-form-item>
        <el-form-item :label="$t('wallet.riskLevel')" prop="risk_level">
            <el-select v-model="formData.risk_level" :placeholder="$t('system.pleaseSelect')">
                <el-option :label="$t('wallet.levelLow')" :value="1" />
                <el-option :label="$t('wallet.levelMedium')" :value="2" />
                <el-option :label="$t('wallet.levelHigh')" :value="3" />
                <el-option :label="$t('wallet.levelCritical')" :value="4" />
            </el-select>
        </el-form-item>
        <el-form-item :label="$t('wallet.priority')" prop="priority">
            <el-input-number v-model="formData.priority" :min="0" />
        </el-form-item>
        <el-form-item :label="$t('wallet.conditions')" prop="conditions">
            <el-input
                v-model="conditionsText"
                type="textarea"
                :rows="4"
                :placeholder="$t('wallet.conditionsPlaceholder')"
            />
        </el-form-item>
        <el-form-item :label="$t('system.status')" prop="status">
            <el-select v-model="formData.status">
                <el-option :label="$t('system.enabled')" :value="1" />
                <el-option :label="$t('system.disabled')" :value="0" />
            </el-select>
        </el-form-item>
        <div class="flex justify-end">
            <el-button type="primary" @click="submit">{{ $t('system.confirm') }}</el-button>
        </div>
    </el-form>
</template>

<script lang="ts" setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useCreate } from '@/composables/curd/useCreate'
import { useShow } from '@/composables/curd/useShow'
import { ElMessage } from 'element-plus'
import { t } from '@/support/helper'

const props = defineProps({
    primary: [String, Number],
    api: String,
})

const { formData, form, loading, submitForm, close } = useCreate(props.api, props.primary)

const conditionsText = ref('')

// conditions 是 JSON 字段：编辑时以文本展示，提交前回写
const syncText = () => {
    const c = (formData as any).value?.conditions
    conditionsText.value = typeof c === 'string' ? c : JSON.stringify(c ?? {}, null, 2)
}

if (props.primary) {
    useShow(props.api, props.primary, formData)
    watch(() => (formData as any).value?.conditions, syncText, { immediate: true })
}

const submit = () => {
    try {
        (formData as any).value.conditions = conditionsText.value
            ? JSON.parse(conditionsText.value)
            : {}
    } catch (e) {
        ElMessage.warning(t('wallet.invalidJson'))
        return
    }

    submitForm(form.value)
}

const emit = defineEmits(['close'])
onMounted(() => {
    close(() => emit('close'))
})
</script>

<template>
    <el-form :model="formData" label-width="120px" ref="form" v-loading="loading" class="pr-4">
        <el-form-item label="规则名称" prop="name">
            <el-input v-model="formData.name" name="name" clearable />
        </el-form-item>
        <el-form-item label="规则编码" prop="code">
            <el-input v-model="formData.code" name="code" placeholder="字母/数字/下划线，唯一" clearable />
        </el-form-item>
        <el-form-item label="场景" prop="type">
            <el-select v-model="formData.type" placeholder="请选择">
                <el-option label="充值" value="recharge" />
                <el-option label="提现" value="withdraw" />
                <el-option label="转账" value="transfer" />
            </el-select>
        </el-form-item>
        <el-form-item label="触发动作" prop="action">
            <el-select v-model="formData.action" placeholder="请选择">
                <el-option label="拒绝" value="reject" />
                <el-option label="人工审核" value="manual_audit" />
                <el-option label="冻结" value="freeze" />
                <el-option label="通知" value="notify" />
            </el-select>
        </el-form-item>
        <el-form-item label="风险等级" prop="risk_level">
            <el-select v-model="formData.risk_level" placeholder="请选择">
                <el-option label="低" :value="1" />
                <el-option label="中" :value="2" />
                <el-option label="高" :value="3" />
                <el-option label="严重" :value="4" />
            </el-select>
        </el-form-item>
        <el-form-item label="优先级" prop="priority">
            <el-input-number v-model="formData.priority" :min="0" />
        </el-form-item>
        <el-form-item label="触发条件" prop="conditions">
            <el-input
                v-model="conditionsText"
                type="textarea"
                :rows="4"
                placeholder='JSON，例：{"single_amount": 10000, "daily_count": 20}'
            />
        </el-form-item>
        <el-form-item label="状态" prop="status">
            <el-select v-model="formData.status">
                <el-option label="启用" :value="1" />
                <el-option label="停用" :value="0" />
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
        ElMessage.warning('触发条件不是合法 JSON')
        return
    }

    submitForm(form.value)
}

const emit = defineEmits(['close'])
onMounted(() => {
    close(() => emit('close'))
})
</script>

<template>
  <el-table :data="data?.columns" class="mt-3" v-loading="loading">
    <el-table-column prop="name" :label="$t('generate.schema.structure.field_name.name')" />
    <el-table-column prop="type" :label="$t('generate.schema.structure.type.name')" />
    <el-table-column prop="nullable" :label="$t('generate.schema.structure.nullable')">
      <template #default="scope">
        <el-tag v-if="scope.row.nullable">{{ $t('system.yes') }}</el-tag>
        <el-tag type="danger" v-else>{{ $t('system.no') }}</el-tag>
      </template>
    </el-table-column>
    <el-table-column prop="default" :label="$t('generate.schema.structure.default')" />
    <el-table-column prop="comment" :label="$t('generate.schema.structure.comment')" />
  </el-table>
</template>

<script lang="ts" setup>
// @ts-nocheck
import { useShow } from '@/composables/curd/useShow'
const props = defineProps({
  id: {
    type: Number,
    required: true
  }
})

// const data = ref<Array<object>>()
const { data, loading } = useShow('schema', props.id)
</script>

<style scoped></style>

<template>
  <el-card class="box-card" shadow="never">
    <template #header>
      <div>
        <span>{{ $t('generate.code.title') }}</span>
      </div>
    </template>
    <div class="w-full sm:w-[40%] mx-auto">
      <el-form :model="structure" ref="form" label-width="100px">
        <el-form-item
          :label="$t('generate.table.name')"
          prop="table"
          :rules="[
            {
              required: true,
              message: $t('generate.table.verify')
            }
          ]"
        >
          <el-input v-model="structure.table" clearable :placeholder="$t('generate.table.placeholder')" />
        </el-form-item>
        <el-form-item :label="$t('generate.code.controller.name')" prop="controller">
          <el-input v-model="structure.controller" clearable :placeholder="$t('generate.code.controller.placeholder')" />
          <div class="mt-1 text-sm text-gray-400">{{ $t('generate.code.controller.tip') }}</div>
        </el-form-item>
        <el-form-item :label="$t('generate.code.model.name')" prop="model">
          <el-input v-model="structure.model" clearable :placeholder="$t('generate.code.model.placeholder')" />
          <div class="mt-1 text-sm text-gray-400">{{ $t('generate.code.model.tip') }}</div>
        </el-form-item>

        <div class="flex justify-between">
          <div class="flex">
            <el-form-item :label="$t('generate.code.paginate')" prop="paginate">
              <el-switch v-model="structure.paginate" inline-prompt :active-text="$t('system.yes')" :inactive-text="$t('system.no')" />
            </el-form-item>
            <el-form-item label-width="15px">
              <div class="text-sm text-gray-300">{{ $t('generate.code.paginate_tip') }}</div>
            </el-form-item>
          </div>
          <!--
          <div class="flex">
            <el-form-item label="Json模式" prop="json">
              <el-switch v-model="structure.json" inline-prompt :active-text="$t('system.yes')" :inactive-text="$t('system.no')" />
            </el-form-item>
            <el-form-item label-width="15px">
              <div class="text-sm text-gray-300">渲染使用 JSON 模式</div>
            </el-form-item>
          </div>-->
        </div>
      </el-form>
    </div>
    <div>
      <el-table :data="structure.fields">
        <el-table-column prop="name" :label="$t('generate.field.name')">
          <template #default="scope">
            <el-input v-model="scope.row.name" :placeholder="$t('generate.field.placeholder')" clearable />
          </template>
        </el-table-column>
        <el-table-column prop="type" :label="$t('generate.schema.structure.type.name')">
          <template #default="scope">
            <el-select v-model="scope.row.type" :placeholder="$t('generate.schema.structure.type.placeholder')" allow-create clearable @change="selectType(scope.row.id)">
              <el-option-group v-for="group in types" :key="group.label" :label="group.label">
                <el-option v-for="item in group.options" :key="item.value" :label="item.value" :value="item.value" />
              </el-option-group>
            </el-select>
          </template>
        </el-table-column>
        <el-table-column prop="length" :label="$t('generate.schema.structure.length')">
          <template #default="scope">
            <el-input-number v-model="scope.row.length" :placeholder="$t('generate.field.length_placeholder')" />
          </template>
        </el-table-column>
        <el-table-column prop="unsigned" label="unsigned" width="120">
          <template #default="scope">
            <el-switch v-model="scope.row.unsigned" inline-prompt :active-text="$t('system.yes')" :inactive-text="$t('system.no')" />
          </template>
        </el-table-column>
        <el-table-column prop="nullable" label="nullable" width="120">
          <template #default="scope">
            <el-switch v-model="scope.row.nullable" inline-prompt :active-text="$t('system.yes')" :inactive-text="$t('system.no')" />
          </template>
        </el-table-column>
        <el-table-column prop="index" :label="$t('generate.field.index')">
          <template #default="scope">
            <el-select v-model="scope.row.index" :placeholder="$t('generate.field.index_placeholder')" allow-create style="width: 120px" clearable>
              <el-option
                v-for="item in [
                  {
                    label: 'unique',
                    value: 'unique'
                  },
                  {
                    label: 'index',
                    value: 'index'
                  },
                  {
                    label: 'fulltext',
                    value: 'fulltext'
                  }
                ]"
                :key="item.value"
                :label="item.label"
                :value="item.value"
              />
            </el-select>
          </template>
        </el-table-column>
        <el-table-column prop="default" :label="$t('generate.schema.structure.default')">
          <template #default="scope">
            <el-input v-model="scope.row.default" :placeholder="$t('generate.field.default_placeholder')" clearable v-if="!isSet" />
            <div class="grid grid-cols-3 gap-1" v-else>
              <el-tag v-for="tag in scope.row.default" :key="tag" closable :disable-transitions="false" @close="delTag(scope.row.id, tag)">
                {{ tag }}
              </el-tag>
              <el-input v-if="tagVisible" ref="InputRef" v-model="tag" class="px-1 min-w-10" size="small" @keyup.enter="handleEnumType(scope.row.id)" @blur="handleEnumType(scope.row.id)" />
              <el-button v-else class="px-1 min-w-10" size="small" @click="showTagInput"> {{ $t('generate.field.add_default') }} </el-button>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="comment" :label="$t('generate.schema.structure.comment')">
          <template #default="scope">
            <el-input v-model="scope.row.comment" :placeholder="$t('generate.field.comment_placeholder')" clearable />
          </template>
        </el-table-column>
        <el-table-column prop="component" :label="$t('generate.schema.structure.form_component')">
          <template #default="scope">
            <el-select v-model="scope.row.component" class="w-full" filterable clearable>
              <el-option v-for="component in formComponents" :key="component" :label="component" :value="component" />
            </el-select>
          </template>
        </el-table-column>
        <el-table-column prop="op" :label="$t('generate.schema.structure.search')" width="150px">
          <template #default="scope">
            <el-select v-model="scope.row.op" :placeholder="$t('generate.schema.structure.search_op.placeholder')" class="w-full">
              <el-option v-for="op in operates" :key="op" :label="op" :value="op" />
            </el-select>
          </template>
        </el-table-column>
        <!--<el-table-column prop="comment" label="注释" />-->
        <el-table-column prop="id" :label="$t('generate.schema.structure.operate')">
          <template #default="scope">
            <el-button type="danger" :icon="Delete" @click="deleteField(scope.row.id)" />
          </template>
        </el-table-column>
      </el-table>
    </div>
    <div class="grid grid-cols-11 px-2 mt-5">
      <div class="flex items-center col-span-10 gap-x-6">
        <div>
          <el-checkbox v-model="structure.createdAt" :label="$t('generate.field.created_updated_at')" />
        </div>
        <div>
          <el-checkbox v-model="structure.deletedAt" :label="$t('generate.schema.default_field.delete_at')" />
        </div>
        <div>
          <el-checkbox v-model="structure.creatorId" :label="$t('generate.field.creator_id')" />
        </div>
        <div>
          <el-select v-model="structure.engine" :placeholder="$t('generate.schema.engine.placeholder')" allow-create style="width: 120px">
            <el-option
              v-for="item in [
                {
                  label: 'innodb',
                  value: 'innodb'
                },
                {
                  label: 'myisam',
                  value: 'myisam'
                },
                {
                  label: 'memeory',
                  value: 'memeory'
                }
              ]"
              :key="item.value"
              :label="item.label"
              :value="item.value"
            />
          </el-select>
        </div>
        <div>
          <el-input v-model="structure.primary" :placeholder="$t('generate.field.primary_placeholder')">
            <template #prepend>{{ $t('generate.field.primary') }}</template>
          </el-input>
        </div>
        <div>
          <el-input v-model="structure.comment" :placeholder="$t('generate.schema.comment.verify')">
            <template #prepend>{{ $t('generate.schema.comment.name') }}</template>
          </el-input>
        </div>
      </div>
      <div>
        <el-button type="primary" :icon="Plus" @click="addField()">{{ $t('generate.field.add') }}</el-button>
      </div>
    </div>
    <div class="flex justify-center w-full pt-5">
      <router-link to="/develop/schemas">
        <el-button>{{ $t('system.back') }}</el-button>
      </router-link>
      <el-button type="primary" @click="submitGenerate(form)" class="ml-5">{{ $t('system.finish') }}</el-button>
    </div>
  </el-card>
</template>
<script lang="ts" setup>
// @ts-nocheck
import { ref } from 'vue'
import type { FormInstance } from 'element-plus'
import http from '@/support/http'

import { Structure as StructureInterface, Field as FieldInterface } from './structure'
import { Delete, Plus } from '@element-plus/icons-vue'
import Message from '@/support/message'
import { t } from '@/support/helper'

const isSet = ref(false)
const getField = (): FieldInterface => {
  return {
    id: 0,
    name: '',
    type: null,
    unsigned: false,
    nullable: false,
    length: 0,
    index: '',
    default: null,
    comment: '',
    component: null,
    op: null
  }
}
const structure = ref<StructureInterface>({
  table: '',
  model: '',
  controller: '',
  paginate: true,
  createdAt: true,
  deletedAt: true,
  primary: 'id',
  comment: '',
  creatorId: true,
  engine: 'innodb',
  json: true,
  fields: [getField()]
})
const deleteField = (id: number) => {
  structure.value.fields = structure.value.fields.filter((field: FieldInterface) => {
    return field.id !== id
  })
}

const addField = () => {
  if (structure.value.fields.length) {
    const field = getField()
    field.id = structure.value.fields[structure.value.fields.length - 1].id + 1
    structure.value.fields.push(field)
  } else {
    structure.value.fields.push(getField())
  }
}
const selectType = (id: number) => {
  structure.value.fields.forEach((field: FieldInterface) => {
    if (field.id === id) {
      if (field.type === 'set' || field.type === 'enum') {
        isSet.value = true
        field.default = []
      } else {
        field.default = null
        isSet.value = false
      }
    }
  })
}

const tag = ref<string | number | null>()
const tagVisible = ref(false)
const showTagInput = () => {
  tagVisible.value = true
}
const delTag = (id: number, v: string | number) => {
  structure.value.fields.forEach((field: FieldInterface) => {
    if (field.id === id) {
      // @ts-ignore
      field.default = field.default?.filter((t: any) => {
        return t !== v
      })
    }
  })
}
const handleEnumType = (id: number) => {
  structure.value.fields.forEach((field: FieldInterface) => {
    if (field.id === id) {
      if (field.type === 'set' || field.type === 'enum') {
        let hasSame = false
        // @ts-ignore
        field.default?.forEach(v => {
          if (tag.value === v) {
            hasSame = true
          }
        })
        if (hasSame) {
          Message.error(t('generate.field.value_exists', { value: tag.value }))
        } else {
          // @ts-ignore
          field.default?.push(tag.value)
          tag.value = null
        }
      }
    }
  })
}
const form = ref<FormInstance>()
const submitGenerate = (formEl: FormInstance | undefined) => {
  if (!formEl) return
  formEl.validate(valid => {
    if (valid) {
      if (!structure.value.fields.length) {
        Message.error(t('generate.field.required'))
      } else {
        http.post('generate', structure.value).then(r => {
          Message.success(t('generate.code.success'))
        })
        // emits('next')
        // generateStore.$reset()
      }
    } else {
      return false
    }
  })
}
const operates: string[] = ['=', '!=', '>', '>=', '<', '<=', 'like', 'RLike', 'LLike', 'in']

const formComponents: string[] = ['cascader', 'date', 'datetime', 'input', 'input-number', 'radio', 'rate', 'select', 'tree', 'tree-select', 'textarea', 'upload']

const types = [
  {
    label: t('generate.field.type_number'),
    options: [
      { value: 'tinyint' },
      { value: 'smallint' },
      { value: 'mediumint' },
      { value: 'int' },
      { value: 'bigint' },
      { value: 'decimal' },
      { value: 'float' },
      { value: 'uuid' },
      { value: 'boolean' }
    ]
  },
  {
    label: t('generate.field.type_datetime'),
    options: [{ value: 'date' }, { value: 'datetime' }, { value: 'timestamp' }, { value: 'time' }]
  },
  {
    label: t('generate.field.type_text'),
    options: [
      { value: 'char' },
      { value: 'varchar' },
      { value: 'tinyText' },
      { value: 'text' },
      { value: 'mediumText' },
      { value: 'longText' },
      { value: 'binary' },
      { value: 'varbinary' },
      { value: 'tinyblob' },
      { value: 'blob' },
      { value: 'mediumblob' },
      { value: 'longblob' },
      { value: 'enum' },
      { value: 'set' }
    ]
  },
  /** {
    label: '空间',
    options: [
      { value: 'geometry' },
      { value: 'point' },
      { value: 'linestring' },
      { value: 'polygon' },
      { value: 'multipoint' },
      { value: 'multilinestring' },
      { value: 'multipolygon' },
      { value: 'geometrycollection' }
    ]
  }, */
  {
    label: 'json',
    options: [{ value: 'json' }]
  }
]
</script>

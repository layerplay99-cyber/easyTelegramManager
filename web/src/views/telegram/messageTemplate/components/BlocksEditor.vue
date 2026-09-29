<template>
  <div class="blocks-editor">
    <div class="mb-2 flex flex-wrap gap-2">
      <el-button size="small" @click="addText">+ 文本</el-button>
      <el-button size="small" @click="addVar">+ 变量</el-button>
      <el-button size="small" type="warning" @click="emojiVisible = true">+ 表情</el-button>
      <el-button size="small" type="success" @click="addEffect">+ 消息特效</el-button>
    </div>

    <div v-if="!blocks.length" class="text-gray-400 text-sm py-4">
      还没有内容，先点上面的按钮添加。
    </div>

    <el-card v-for="(block, index) in blocks" :key="index" class="mb-2" shadow="never">
      <div class="flex items-start gap-2">
        <el-tag size="small" :type="tagType(block.t)">{{ blockLabel(block.t) }}</el-tag>

        <div class="flex-1">
          <!-- 文本 -->
          <el-input
            v-if="block.t === 'text'"
            v-model="block.v"
            type="textarea"
            :rows="2"
            placeholder="输入文本"
          />

          <!-- 变量 -->
          <el-input v-else-if="block.t === 'var'" v-model="block.k" placeholder="变量名，如 nickname" />

          <!-- 自定义 / 动态 emoji -->
          <div v-else-if="block.t === 'emoji'" class="flex items-center gap-2">
            <span class="text-2xl">{{ block.alt || '⭐' }}</span>
            <el-input v-model="block.id" placeholder="emoji_id" class="flex-1" />
            <el-input v-model="block.alt" placeholder="回退字符" style="width: 120px" />
          </div>

          <!-- 消息特效 -->
          <el-input v-else-if="block.t === 'effect'" v-model="block.id" placeholder="effect_id" />

          <!-- 贴纸 -->
          <el-input v-else-if="block.t === 'sticker'" v-model="block.file_id" placeholder="file_id（须属于发送账号）" />
        </div>

        <el-button size="small" type="danger" plain @click="remove(index)">删除</el-button>
        <el-button size="small" plain @click="moveUp(index)" :disabled="index === 0">↑</el-button>
      </div>
    </el-card>

    <!-- 表情素材库 -->
    <el-dialog v-model="emojiVisible" title="选择表情" width="720px">
      <div class="mb-2 flex gap-2">
        <el-select v-model="emojiType" placeholder="类型" clearable style="width: 180px">
          <el-option v-for="t in types" :key="t.value" :label="t.label" :value="t.value" />
        </el-select>
        <el-button @click="loadEmojis">搜索</el-button>
      </div>

      <div class="grid grid-cols-6 gap-2" style="max-height: 380px; overflow: auto">
        <div
          v-for="item in emojis"
          :key="item.id"
          class="border rounded p-2 text-center cursor-pointer hover:bg-gray-100"
          @click="pickEmoji(item)"
        >
          <div class="text-2xl">{{ item.unicode || '⭐' }}</div>
          <div class="text-xs text-gray-500 truncate">{{ item.name }}</div>
        </div>
      </div>

      <div v-if="!emojis.length" class="text-gray-400 text-sm py-4">
        暂无素材。可先在群里发自定义表情让系统采集，或在「表情素材」里手动添加。
      </div>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
import { ref, watch } from 'vue'
import http from '@/support/http'

const props = defineProps({
  modelValue: {
    type: Array,
    default: () => [],
  },
})

const emit = defineEmits(['update:modelValue'])

const blocks = ref<any[]>([...(props.modelValue || [])])

watch(
  blocks,
  value => {
    emit('update:modelValue', value)
  },
  { deep: true }
)

watch(
  () => props.modelValue,
  value => {
    if (JSON.stringify(value) !== JSON.stringify(blocks.value)) {
      blocks.value = [...(value || [])]
    }
  },
  { deep: true }
)

const types = [
  { label: '官方 emoji', value: 'unicode' },
  { label: '自定义/动态 emoji', value: 'custom_emoji' },
  { label: '贴纸', value: 'sticker' },
  { label: '消息特效', value: 'message_effect' },
]

const emojiVisible = ref(false)
const emojiType = ref('custom_emoji')
const emojis = ref<any[]>([])

const loadEmojis = () => {
  http
    .get('telegram/emojis', {
      type: emojiType.value || undefined,
      limit: 100,
    })
    .then(r => {
      emojis.value = r.data.data?.data || r.data.data || []
    })
}

const pickEmoji = (item: any) => {
  if (item.type === 'sticker') {
    blocks.value.push({ t: 'sticker', file_id: item.file_id || '' })
  } else if (item.type === 'message_effect') {
    blocks.value.push({ t: 'effect', id: item.telegram_id || '' })
  } else {
    blocks.value.push({
      t: 'emoji',
      id: String(item.telegram_id || ''),
      alt: item.unicode || item.name || '⭐',
    })
  }

  emojiVisible.value = false
}

const addText = () => blocks.value.push({ t: 'text', v: '' })
const addVar = () => blocks.value.push({ t: 'var', k: 'nickname' })
const addEffect = () => blocks.value.push({ t: 'effect', id: '' })

const remove = (index: number) => blocks.value.splice(index, 1)

const moveUp = (index: number) => {
  if (index === 0) return
  const item = blocks.value[index]
  blocks.value.splice(index, 1)
  blocks.value.splice(index - 1, 0, item)
}

const blockLabel = (t: string) =>
  ({ text: '文本', var: '变量', emoji: '表情', sticker: '贴纸', effect: '特效' } as any)[t] || t

const tagType = (t: string) =>
  ({ text: 'info', var: '', emoji: 'warning', sticker: 'success', effect: 'danger' } as any)[t] || 'info'
</script>

<template>
  <div class="blocks-editor">
    <div class="mb-2 flex flex-wrap gap-2">
      <el-button size="small" @click="addText">{{ $t('template.addText') }}</el-button>
      <el-button size="small" @click="addVar">{{ $t('template.addVar') }}</el-button>
      <el-button size="small" type="warning" @click="emojiVisible = true">{{ $t('template.addEmoji') }}</el-button>
      <el-button size="small" type="success" @click="addEffect">{{ $t('template.addEffect') }}</el-button>
    </div>

    <div v-if="!blocks.length" class="text-gray-400 text-sm py-4">
      {{ $t('template.emptyTip') }}
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
            :placeholder="$t('template.inputText')"
          />

          <!-- 变量 -->
          <el-input v-else-if="block.t === 'var'" v-model="block.k" :placeholder="$t('template.varNamePlaceholder')" />

          <!-- 自定义 / 动态 emoji -->
          <div v-else-if="block.t === 'emoji'" class="flex items-center gap-2">
            <span class="text-2xl">{{ block.alt || '⭐' }}</span>
            <el-input v-model="block.id" placeholder="emoji_id" class="flex-1" />
            <el-input v-model="block.alt" :placeholder="$t('template.fallbackChar')" style="width: 120px" />
          </div>

          <!-- 消息特效 -->
          <el-input v-else-if="block.t === 'effect'" v-model="block.id" placeholder="effect_id" />

          <!-- 贴纸 -->
          <el-input v-else-if="block.t === 'sticker'" v-model="block.file_id"
            :placeholder="$t('template.fileIdPlaceholder')" />
        </div>

        <el-button size="small" type="danger" plain @click="remove(index)">{{ $t('system.delete') }}</el-button>
        <el-button size="small" plain @click="moveUp(index)" :disabled="index === 0">↑</el-button>
      </div>
    </el-card>

    <!-- 表情素材库 -->
    <el-dialog v-model="emojiVisible" :title="$t('template.selectEmoji')" width="720px">
      <div class="mb-2 flex gap-2">
        <el-select v-model="emojiType" :placeholder="$t('system.status')" clearable style="width: 180px">
          <el-option v-for="t in types" :key="t.value" :label="t.label" :value="t.value" />
        </el-select>
        <el-button @click="loadEmojis">{{ $t('system.search') }}</el-button>
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
        {{ $t('template.noAssets') }}
      </div>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
import { computed, ref, watch } from 'vue'
import http from '@/support/http'
import { t } from '@/support/helper'

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

const types = computed(() => [
  { label: t('template.typeUnicode'), value: 'unicode' },
  { label: t('template.typeCustomEmoji'), value: 'custom_emoji' },
  { label: t('template.typeSticker'), value: 'sticker' },
  { label: t('template.typeMessageEffect'), value: 'message_effect' },
])

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
  ({
    text: t('template.blockText'),
    var: t('template.blockVar'),
    emoji: t('template.blockEmoji'),
    sticker: t('template.blockSticker'),
    effect: t('template.blockEffect'),
  } as any)[t] || t

const tagType = (t: string) =>
  ({ text: 'info', var: '', emoji: 'warning', sticker: 'success', effect: 'danger' } as any)[t] || 'info'
</script>

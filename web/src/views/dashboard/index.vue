<template>
  <div>
    <div class="w-full h-32 bg-gradient-to-r from-blue-500 to-indigo-600 relative">
      <div class="absolute top-4 left-4 flex items-center">
        <div class="ml-4 text-white text-lg font-medium">
          欢迎：{{ username }}
        </div>
      </div>
      <div class="absolute bottom-4 left-4 text-white">
        {{ itsTimeDo }}
      </div>
    </div>
  </div>
</template>
<script lang="ts" setup>
import { computed } from 'vue'
import { useUserStore } from '@/stores/modules/user'

const userStore = useUserStore()

const username = computed(() => {
  return userStore.getUsername
})

const avatar = computed(() => {
  return userStore.getAvatar
})
const itsTimeDo = computed(() => {
  const date = new Date()
  const now = date.getHours()
  return '努力就有回报，奋斗只争朝夕！😊'
})

const context = computed(() => {
  const contexts: string[] = [
    '资本主义社会里的民主是一种残缺不全的，贫乏和虚伪和民主，是只供富人，只供少数人享受的民主',
    '资本来到世间，从头到脚，每个毛孔都滴着血和肮脏的东西',
    '既然掠夺给少数人造成了天然的权利，那么多数人就只得积聚足够的力量，来取得夺回他们被夺去的一切的天然权利',
    '资本家有百分之五十的利润，就会铤而走险；有了百分之一百的利润就敢践踏人间一切法律；有了百分之三百的利润就敢冒上绞刑架的危险'
  ]

  return contexts[Math.floor(Math.random() * contexts.length)]
})

function isInRange(compare: number, min: number, max: number) {
  return compare >= min && compare < max
}
</script>

<template>
  <SectionHeader :title="computedTitle">
    <BaseButton
      v-if="menuItems.length"
      icon="dots-vertical"
      only-icon
      popup-identifier="page-menu"
      type="black"
      @click="toggleMenu"
    />

    <BaseMenu
      v-if="menuItems.length"
      id="page-menu"
      ref="menu"
      :model="menuItems"
    />
  </SectionHeader>

  <router-view />
</template>

<script setup>
import BaseButton from "../basecomponents/BaseButton.vue"
import BaseMenu from "../basecomponents/BaseMenu.vue"
import { provide, ref, watch, computed } from "vue"
import { useRoute } from "vue-router"
import { useI18n } from "vue-i18n"
import SectionHeader from "../layout/SectionHeader.vue"

const route = useRoute()
const { t } = useI18n()

const menu = ref(null)

const menuItems = ref([])

provide("layoutMenuItems", menuItems)

watch(
  () => route.name,
  () => {
    menuItems.value = []
  },
  { immediate: true }
)

const computedTitle = computed(() => {
  if (route.path.includes("/resources/pages/layouts")) {
    return ''
  }

  return t("Pages")
})

const toggleMenu = (event) => menu.value.toggle(event)
</script>

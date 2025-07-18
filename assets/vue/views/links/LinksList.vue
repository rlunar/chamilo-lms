<template>
  <div>
    <BaseToolbar v-if="securityStore.isAuthenticated && isAllowedToEdit">
      <BaseButton
        :label="t('Add a link')"
        icon="link-add"
        type="black"
        @click="redirectToCreateLink"
      />
      <BaseButton
        :label="t('Add a category')"
        icon="folder-plus"
        type="black"
        @click="redirectToCreateLinkCategory"
      />
    </BaseToolbar>

    <LinkCategoryCard v-if="isLoading">
      <template #header>
        <Skeleton class="h-6 w-48" />
      </template>
      <div class="flex flex-col gap-4">
        <Skeleton class="ml-2 h-6 w-52" />
        <Skeleton class="ml-2 h-6 w-64" />
        <Skeleton class="ml-2 h-6 w-60" />
        <Skeleton class="ml-2 h-6 w-52" />
        <Skeleton class="ml-2 h-6 w-60" />
      </div>
    </LinkCategoryCard>

    <div v-if="!isLoading && !linksWithoutCategory.length && !categories.length">
      <!-- Render the image and create button -->
      <EmptyState
        :summary="t('Add your first link to this course')"
        icon="link"
      >
        <BaseButton
          :label="t('Add a link')"
          class="mt-4"
          icon="link-add"
          type="primary"
          @click="redirectToCreateLink"
        />
      </EmptyState>
    </div>

    <div
      v-if="!isLoading"
      class="flex flex-col gap-4"
    >
      <!-- Render the list of links without a category -->
      <LinkCategoryCard
        v-if="linksWithoutCategory.length > 0"
        :showHeader="false"
      >
        <template #header>
          <h5>{{ t("General") }}</h5>
        </template>

        <ul>
          <li
            v-for="link in linksWithoutCategory"
            :key="link.id"
            class="mb-4"
          >
            <LinkItem
              :isLinkValid="linkValidationResults[link.iid]"
              :link="link"
              @check="checkLink(link.iid, link.url)"
              @delete="confirmDeleteLink(link)"
              @edit="editLink"
              @toggle="toggleVisibility"
              @move-up="moveUp(link.iid, link.position)"
              @move-down="moveDown(link.iid, link.position)"
            />
          </li>
        </ul>
      </LinkCategoryCard>

      <!-- Render the list of categorized links -->
      <LinkCategoryCard
        v-for="category in categories"
        :key="category.info.id"
        :showHeader="true"
      >
        <template #header>
          <div class="flex justify-between">
            <div class="flex items-center">
              <BaseIcon
                class="mr-2"
                icon="folder-generic"
                size="big"
              />
              <h5>{{ category.info.title }}</h5>
            </div>
            <div
              v-if="securityStore.isAuthenticated && isCurrentTeacher"
              class="flex gap-2"
            >
              <BaseButton
                :label="t('Edit')"
                icon="edit"
                size="small"
                type="black"
                @click="editCategory(category)"
              />
              <BaseButton
                :icon="isVisible(category.info.visible) ? 'eye-on' : 'eye-off'"
                :label="t('Change visibility')"
                size="small"
                type="black"
                @click="toggleCategoryVisibility(category)"
              />
              <BaseButton
                :label="t('Delete')"
                icon="delete"
                size="small"
                type="danger"
                @click="confirmDeleteCategory(category)"
              />
            </div>
          </div>
          <p v-if="category.info.description">{{ category.info.description }}</p>
        </template>

        <ul>
          <li
            v-for="link in category.links"
            :key="link.id"
          >
            <LinkItem
              :isLinkValid="linkValidationResults[link.iid]"
              :link="link"
              @check="checkLink(link.iid, link.url)"
              @delete="confirmDeleteLink(link)"
              @edit="editLink"
              @toggle="toggleVisibility"
              @move-up="moveUp(link.iid, link.position)"
              @move-down="moveDown(link.iid, link.position)"
            />
          </li>
        </ul>
        <p v-if="!category.links || category.links.length === 0">
          {{ t("There are no links in this category") }}
        </p>
      </LinkCategoryCard>
    </div>

    <BaseDialogDelete
      v-model:is-visible="isDeleteLinkDialogVisible"
      :item-to-delete="linkToDeleteString"
      @confirm-clicked="deleteLink"
      @cancel-clicked="isDeleteLinkDialogVisible = false"
    />
    <BaseDialogDelete
      v-model:is-visible="isDeleteCategoryDialogVisible"
      @confirm-clicked="deleteCategory"
      @cancel-clicked="isDeleteCategoryDialogVisible = false"
    >
      <div v-if="categoryToDelete">
        <p class="mb-2 font-semibold">{{ categoryToDelete.info.title }}</p>
        <p>{{ t("With links") }}: {{ (categoryToDelete.links || []).map((l) => l.title).join(", ") }}</p>
      </div>
    </BaseDialogDelete>
  </div>
</template>

<script setup>
import EmptyState from "../../components/EmptyState.vue"
import BaseButton from "../../components/basecomponents/BaseButton.vue"
import BaseToolbar from "../../components/basecomponents/BaseToolbar.vue"
import { computed, onMounted, ref } from "vue"
import { useRoute, useRouter } from "vue-router"
import { useI18n } from "vue-i18n"
import BaseIcon from "../../components/basecomponents/BaseIcon.vue"
import LinkItem from "../../components/links/LinkItem.vue"
import { useNotification } from "../../composables/notification"
import LinkCategoryCard from "../../components/links/LinkCategoryCard.vue"
import linkService from "../../services/linkService"
import BaseDialogDelete from "../../components/basecomponents/BaseDialogDelete.vue"
import Skeleton from "primevue/skeleton"
import { isVisible, toggleVisibilityProperty, visibilityFromBoolean } from "../../components/links/linkVisibility"
import { useSecurityStore } from "../../store/securityStore"
import { useCidReq } from "../../composables/cidReq"
import { checkIsAllowedToEdit } from "../../composables/userPermissions"

const route = useRoute()
const router = useRouter()
const securityStore = useSecurityStore()
const { cid, sid, gid } = useCidReq()
const isAllowedToEdit = ref(false)

const { t } = useI18n()

const notifications = useNotification()

const isCurrentTeacher = computed(() => securityStore.isCurrentTeacher)

const linksWithoutCategory = ref([])
const categories = ref([])

const selectedLink = ref(null)
const selectedCategory = ref(null)

const isDeleteLinkDialogVisible = ref(false)
const linkToDelete = ref(null)
const linkToDeleteString = computed(() => {
  if (linkToDelete.value === null) {
    return ""
  }
  return linkToDelete.value.title
})

const isDeleteCategoryDialogVisible = ref(false)
const categoryToDelete = ref(null)

const isLoading = ref(true)

const linkValidationResults = ref({})
const isToggling = ref({})

onMounted(async () => {
  isAllowedToEdit.value = await checkIsAllowedToEdit(true, true, true)
  linksWithoutCategory.value = []
  categories.value = []
  await fetchLinks()
})

function editLink(link) {
  selectedLink.value = { ...link }
  router.push({
    name: "UpdateLink",
    params: { id: link.iid },
    query: route.query,
  })
}

function confirmDeleteLink(link) {
  linkToDelete.value = link
  isDeleteLinkDialogVisible.value = true
}

async function deleteLink() {
  try {
    await linkService.deleteLink(linkToDelete.value.id)
    linkToDelete.value = null
    isDeleteLinkDialogVisible.value = false
    notifications.showSuccessNotification(t("Link deleted"))
    await fetchLinks()
  } catch (error) {
    console.error("Error deleting link:", error)
    notifications.showErrorNotification(t("Could not delete link"))
  }
}

async function checkLink(id, url) {
  try {
    const result = await linkService.checkLink(url, id)
    linkValidationResults.value = { ...linkValidationResults.value, [id]: { isValid: result.isValid } }
  } catch (error) {
    console.error("Error checking link:", error)
    linkValidationResults.value = {
      ...linkValidationResults.value,
      [id]: { isValid: false, message: error.message || "Link validation failed" },
    }
  }
}

async function toggleVisibility(link) {
  if (isToggling.value[link.iid]) return
  isToggling.value = { ...isToggling.value, [link.iid]: true }

  try {
    const newVisible = !isVisible(link.linkVisible)
    const updatedLink = await linkService.toggleLinkVisibility(link.iid, newVisible, cid, sid)
    const newFlagValue = visibilityFromBoolean(updatedLink.linkVisible)

    linksWithoutCategory.value
      .filter((l) => l.iid === link.iid)
      .forEach((l) => (l.linkVisible = newFlagValue))

    categories.value
      .flatMap((c) => c.links || [])
      .filter((l) => l.iid === link.iid)
      .forEach((l) => (l.linkVisible = newFlagValue))

    notifications.showSuccessNotification(t("Link visibility updated"))
  } catch (err) {
    notifications.showErrorNotification(t("Could not change visibility of link"))
  } finally {
    isToggling.value = { ...isToggling.value, [link.iid]: false }
  }
}

async function moveUp(id, position) {
  let newPosition = parseInt(position) - 1
  if (newPosition < 0) {
    newPosition = 0
  }
  try {
    await linkService.moveLink(id, newPosition)
    notifications.showSuccessNotification(t("Link moved up"))
    await fetchLinks()
  } catch (error) {
    notifications.showErrorNotification(t("Could not move link up"))
  }
}

async function moveDown(id, position) {
  const newPosition = parseInt(position) + 1
  try {
    await linkService.moveLink(id, newPosition)
    notifications.showSuccessNotification(t("Link moved down"))
    await fetchLinks()
  } catch (error) {
    notifications.showErrorNotification(t("Could not move link down"))
  }
}

function redirectToCreateLink() {
  router.push({
    name: "CreateLink",
    query: route.query,
  })
}

function redirectToCreateLinkCategory() {
  router.push({
    name: "CreateLinkCategory",
    query: route.query,
  })
}

function editCategory(category) {
  selectedCategory.value = { ...category }
  router.push({
    name: "UpdateLinkCategory",
    params: { id: category.info.id },
    query: route.query,
  })
}

function confirmDeleteCategory(category) {
  categoryToDelete.value = category
  isDeleteCategoryDialogVisible.value = true
}

async function deleteCategory() {
  try {
    await linkService.deleteCategory(categoryToDelete.value.info.id)
    categoryToDelete.value = null
    isDeleteCategoryDialogVisible.value = false
    notifications.showSuccessNotification(t("Category deleted"))
    await fetchLinks()
  } catch (error) {
    console.error("Error deleting category:", error)
    notifications.showErrorNotification(t("Could not delete category"))
  }
}

async function toggleCategoryVisibility(category) {
  const visibility = toggleVisibilityProperty(category.info.visible)
  try {
    const updatedLinkCategory = await linkService.toggleCategoryVisibility(
      category.info.id,
      isVisible(visibility),
      cid,
      sid,
    )
    category.info.visible = visibilityFromBoolean(updatedLinkCategory.linkCategoryVisible)
    notifications.showSuccessNotification(t("Visibility of category changed"))
  } catch (error) {
    console.error("Error updating link visibility:", error)
    notifications.showErrorNotification(t("Could not change visibility of category"))
  }
}

function exportToPDF() {
  // TODO
}

function toggleTeacherStudent() {
  // TODO
}

async function fetchLinks() {
  isLoading.value = true
  const params = {
    "resourceNode.parent": route.query.parent || null,
    cid: route.query.cid || null,
    sid: route.query.sid || null,
  }

  try {
    const data = await linkService.getLinks(params)
    linksWithoutCategory.value = data.linksWithoutCategory || []
    categories.value = Object.values(data.categories || {})
  } catch (error) {
    console.error("Error fetching links:", error)
    notifications.showErrorNotification(t("Could not retrieve links"))
  } finally {
    isLoading.value = false
  }
}
</script>

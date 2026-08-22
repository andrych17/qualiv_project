<!-- ponytail: recursive folder row — §3A folder tree (standalone mode). Self-referencing SFC,
     named so <FolderTreeNode> can nest itself without a separate registration file. -->
<script setup lang="ts">
defineOptions({ name: 'FolderTreeNode' })

export interface FolderNode {
  id: number
  name: string
  document_count: number
  access_flag: string
  children: FolderNode[]
}

defineProps<{
  node: FolderNode
  activeId: number | null
  depth?: number
}>()

defineEmits<{ select: [id: number] }>()
</script>

<template>
  <li>
    <button
      type="button"
      class="flex w-full items-center justify-between gap-2 rounded-sm px-2 py-1.5 text-left text-sm transition hover:bg-surface-50"
      :class="activeId === node.id ? 'bg-accent/10 font-medium text-accent' : 'text-ink-700'"
      :style="{ paddingLeft: `${8 + (depth ?? 0) * 14}px` }"
      @click="$emit('select', node.id)"
    >
      <span class="truncate">{{ node.name }}</span>
      <span class="shrink-0 text-xs text-ink-500">{{ node.document_count }}</span>
    </button>
    <ul v-if="node.children.length">
      <FolderTreeNode
        v-for="child in node.children"
        :key="child.id"
        :node="child"
        :active-id="activeId"
        :depth="(depth ?? 0) + 1"
        @select="$emit('select', $event)"
      />
    </ul>
  </li>
</template>

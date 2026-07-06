// ponytail: Simple helper to handle query string filters via Inertia
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { debounce } from './debounce'

export function useTableFilters(routeName: string, initialFilters: Record<string, any>) {
  const filters = ref({ ...initialFilters })

  watch(
    filters,
    debounce(() => {
      router.get(route(routeName), filters.value, {
        preserveState: true,
        replace: true,
      })
    }, 400),
    { deep: true }
  )

  return { filters }
}

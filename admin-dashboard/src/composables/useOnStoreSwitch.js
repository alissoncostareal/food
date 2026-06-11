import { onBeforeUnmount, onMounted } from 'vue'

export function useOnStoreSwitch(callback) {
  onMounted(() => {
    window.addEventListener('partiumenu:store-switched', callback)
  })

  onBeforeUnmount(() => {
    window.removeEventListener('partiumenu:store-switched', callback)
  })
}

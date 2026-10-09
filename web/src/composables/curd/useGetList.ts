import http from '@/support/http'
import { provide, ref, unref } from 'vue'
import { Code } from '@/enum/app'
import Message from '@/support/message'

const initLimit = 10
const initPage = 1
const initTotal = 10

// get table list
export function useGetList(path: string, isPaginate: boolean = true) {
  const data = ref<object>()
  const page = ref<number>(initPage)
  const limit = ref<number>(initLimit)
  const total = ref<number>(initTotal)
  const query = ref<object>({})
  if (isPaginate) {
    query.value = Object.assign({
      page: page.value,
      limit: limit.value,
    })
  }

  const loading = ref(true)

  // 在途请求去重：相同 path + 相同 query 的请求尚未结束时再次触发，直接跳过。
  // 相同参数的结果必然一致，重复发起只会放大后端压力（el-pagination 的
  // current-change / size-change 回调在 total 变化时会补发请求）。
  let inflightKey: string | null = null

  // fetch list
  function getList() {
    const key = path + '|' + JSON.stringify(unref(query))

    if (inflightKey === key) {
      return
    }

    inflightKey = key

    // when table's data page >= 100, it will loading
    if (page.value >= 100) {
      loading.value = true
    }
    http
      .get(path, unref(query))
      .then(r => {
        closeLoading()
        if (r.data.code === Code.SUCCESS) {
                    data.value = r.data
                    // 分页总数：后端返回的是分页结构 { data: { total, ... } }，
                    // 原写法取 r.data.total 恒为 undefined，导致分页组件 total 为 NaN、
                    // el-pagination 反复触发 current-change，列表接口被重复请求。
                    total.value = r.data?.total ?? r.data?.data?.total ?? 0
                  } else {
          Message.error(r.data.message)
        }
      })
      .catch(() => {
        closeLoading()
      })
      .finally(() => {
        if (inflightKey === key) {
          inflightKey = null
        }
        closeLoading()
      })
  }

  // close loading
  function closeLoading() {
    loading.value = false
  }
  // search
  function search() {
    getList()
  }

  // reset
  function reset() {
    resetPage()
    query.value = Object.assign(isPaginate ? { page: page.value, limit: limit.value } : {})
    getList()
  }

  // change page
  function changePage(p: number) {
    page.value = p
    // @ts-ignore
    query.value.page = p
    search()
  }

  function resetPage() {
    page.value = 1
  }

  // change limit
  function changeLimit(l: number) {
    limit.value = l
    resetPage()
    // @ts-ignore
    query.value.page = 1
    // @ts-ignore
    query.value.limit = l

    search()
  }

  // provider for paginate component
  provide('paginate', { page, limit, total, changePage, changeLimit })

  return { data, query, search, reset, loading }
}

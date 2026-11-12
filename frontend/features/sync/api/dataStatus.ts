import type { ApiRequest } from '~/composables/useApi'
import type { DataStatus } from '../types'

export function fetchDataStatus(api: ApiRequest): Promise<DataStatus> {
  return api<DataStatus>('/api/data-status')
}

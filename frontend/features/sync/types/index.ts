export type SyncSourceCode = 'platform_api' | 'bitrix'

export interface ResourceStatus {
  resource: string
  lastRunAt: string | null
  lastStatus: 'running' | 'success' | 'failed' | null
  lastSuccessAt: string | null
  /** Admins only */
  error: string | null
}

export interface SourceStatus {
  source: SyncSourceCode
  configured: boolean
  /** Oldest last success among the resources: all data is at least this fresh */
  dataAsOf: string | null
  stale: boolean
  failing: boolean
  resources: ResourceStatus[]
}

export interface DataStatus {
  checkedAt: string
  sources: SourceStatus[]
}

export type SourceHealth = 'ok' | 'stale' | 'failing' | 'off'

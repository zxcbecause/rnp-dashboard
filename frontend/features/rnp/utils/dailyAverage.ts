/** Average per day as the API sends it ("4.1") in the Russian notation of the dashboard ("4,1"). */
export function formatPerDay(value: string): string {
  return value.replace('.', ',')
}

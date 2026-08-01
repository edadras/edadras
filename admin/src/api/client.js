import axios from 'axios'

/**
 * One axios instance for the whole panel. It carries the bearer token, the
 * club slug and the chosen language on every request, which is exactly what
 * the API's ResolveTenant and SetLocale middleware expect.
 */
const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api/v1',
  headers: { Accept: 'application/json' },
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('gymflow.token')
  const tenant = localStorage.getItem('gymflow.tenant')
  const locale = localStorage.getItem('gymflow.locale') || 'fa'

  if (token) config.headers.Authorization = `Bearer ${token}`
  if (tenant) config.headers['X-Tenant'] = tenant
  config.headers['X-Locale'] = locale

  return config
})

let onUnauthenticated = () => {}

export function handleUnauthenticated(callback) {
  onUnauthenticated = callback
}

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      onUnauthenticated()
    }

    return Promise.reject(error)
  },
)

/** Pulls the first readable message out of a Laravel error response. */
export function errorMessage(error, fallback = 'Something went wrong.') {
  const data = error?.response?.data

  if (!data) return error?.message || fallback
  if (data.errors) return Object.values(data.errors).flat()[0]

  return data.message || fallback
}

export default api

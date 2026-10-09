import { createContext, useContext, useState, useEffect, useCallback } from 'react'
import { useLocation } from 'react-router-dom'

type Msg = { id: number; text: string; err: boolean }
type ToastCtx = { toast: (t: string) => void; dismiss: (id: number) => void; msgs: Msg[] }

const Ctx = createContext<ToastCtx | null>(null)

export function ToastProvider({ children }: { children: React.ReactNode }) {
  const [msgs, setMsgs] = useState<Msg[]>([])
  const location = useLocation()

  useEffect(() => { setMsgs([]) }, [location.key])

  const toast = useCallback((text: string) => {
    const id = Date.now() + Math.random()
    setMsgs((p) => [...p, { id, text, err: /^Gagal|Error/i.test(text) }])
    setTimeout(() => setMsgs((p) => p.filter((m) => m.id !== id)), 6000)
  }, [])

  const dismiss = useCallback((id: number) => {
    setMsgs((p) => p.filter((m) => m.id !== id))
  }, [])

  return (
    <Ctx.Provider value={{ toast, dismiss, msgs }}>
      {children}
    </Ctx.Provider>
  )
}

export function useToast() {
  const v = useContext(Ctx)
  if (!v) throw new Error('useToast outside ToastProvider')
  return v
}

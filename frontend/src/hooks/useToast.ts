import { useState, useEffect, useCallback } from 'react'
import { useLocation } from 'react-router-dom'

type Msg = { id: number; text: string; err: boolean }

export function useToast() {
  const [msgs, setMsgs] = useState<Msg[]>([])
  const location = useLocation()
  const counterRef = useState(0)[1]

  const toast = useCallback((text: string) => {
    const id = Date.now() + Math.random()
    setMsgs((prev) => [...prev, { id, text, err: /^Gagal|Error/i.test(text) }])
    setTimeout(() => setMsgs((prev) => prev.filter((m) => m.id !== id)), 6000)
  }, [])

  const dismiss = useCallback((id: number) => {
    setMsgs((prev) => prev.filter((m) => m.id !== id))
  }, [])

  useEffect(() => {
    setMsgs([])
  }, [location.key])

  return { toast, dismiss, msgs }
}
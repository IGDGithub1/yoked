import { useEffect, useRef, useState } from 'react'

/**
 * Barcode scanning, via the native BarcodeDetector.
 *
 * No library. The two usual candidates (ZXing, Quagga) are 200–900kB of wasm or
 * minified JS for something Chrome and Android WebView do natively, and this app
 * ships a 66kB bundle. What the native API costs instead is coverage: it is
 * absent in iOS Safari, which is a real target for an installable PWA — so
 * `supported` is checked before the camera is ever requested, and the typed-UPC
 * path is a peer rather than an error state. A number pad is a perfectly good
 * scanner for the once-a-week case.
 *
 * Lookup is server-side (GET /nutrition/barcode/{upc}) — cache, then Open Food
 * Facts, then the model on the UPC. This component only turns a camera frame
 * into digits.
 */
export default function Scanner({ onDetected, onClose }) {
  const supported = typeof window !== 'undefined' && 'BarcodeDetector' in window

  const videoRef = useRef(null)
  // The stream and the RAF handle live in refs, not state: stopping the camera
  // must not depend on a re-render happening first.
  const streamRef = useRef(null)
  const rafRef = useRef(null)

  const [status, setStatus] = useState(supported ? 'starting' : 'manual')
  const [error, setError] = useState(null)
  const [typed, setTyped] = useState('')

  useEffect(() => {
    if (!supported) return

    let cancelled = false
    // Detected in a ref rather than state so the scan loop can bail
    // synchronously — a second detection while React re-renders would fire
    // onDetected twice for one scan.
    let done = false

    async function start() {
      try {
        const detector = new window.BarcodeDetector({
          // Retail formats only. Including the 2D formats makes the detector
          // slower for no benefit — food packaging is EAN/UPC.
          formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e'],
        })

        const stream = await navigator.mediaDevices.getUserMedia({
          // The rear camera, and a resolution high enough to resolve the bars.
          // 'environment' is a hint, not a guarantee, but it is the difference
          // between scanning a packet and filming your own face.
          video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 } },
          audio: false,
        })
        if (cancelled) {
          stream.getTracks().forEach((t) => t.stop())
          return
        }
        streamRef.current = stream

        const video = videoRef.current
        if (!video) return
        video.srcObject = stream
        await video.play()
        setStatus('scanning')

        const tick = async () => {
          if (cancelled || done) return
          try {
            const found = await detector.detect(video)
            if (found.length > 0 && !done) {
              const code = String(found[0].rawValue || '').replace(/\D/g, '')
              if (code.length >= 8) {
                done = true
                stop()
                onDetected(code)
                return
              }
            }
          } catch {
            // A single failed frame is normal — the video may not have a frame
            // yet, or it is mid-resize. Keep going; a genuine failure shows up
            // as never detecting, which the manual path covers.
          }
          rafRef.current = requestAnimationFrame(tick)
        }
        rafRef.current = requestAnimationFrame(tick)
      } catch (e) {
        if (cancelled) return
        // A refused permission is not an error to apologise for — it is a choice,
        // and the manual path still works. Say what to do, not what went wrong.
        setError(
          e?.name === 'NotAllowedError'
            ? 'No camera access. Type the number under the barcode instead.'
            : 'Could not start the camera. Type the number under the barcode instead.'
        )
        setStatus('manual')
      }
    }

    function stop() {
      if (rafRef.current) cancelAnimationFrame(rafRef.current)
      rafRef.current = null
      const s = streamRef.current
      if (s) s.getTracks().forEach((t) => t.stop())
      streamRef.current = null
    }

    start()

    // The camera light staying on after the sheet closes is the kind of thing
    // that gets an app deleted.
    return () => {
      cancelled = true
      stop()
    }
  }, [supported, onDetected])

  return (
    <div className="stack-sm scanner">
      {status !== 'manual' && (
        <div className="scanner-frame">
          {/* muted + playsInline: without playsInline iOS takes the video
              fullscreen, and without muted autoplay is blocked outright. */}
          <video ref={videoRef} muted playsInline className="scanner-video" />
          <div className="scanner-reticle" aria-hidden="true" />
        </div>
      )}

      <p className="tiny muted" style={{ margin: 0 }}>
        {status === 'starting' && 'Starting the camera…'}
        {status === 'scanning' && 'Point it at the barcode.'}
        {status === 'manual' && !supported &&
          'This browser cannot scan. Type the number under the barcode.'}
      </p>

      {error && <p className="error">{error}</p>}

      <form
        className="row"
        onSubmit={(e) => {
          e.preventDefault()
          const code = typed.replace(/\D/g, '')
          if (code.length >= 8) onDetected(code)
        }}
      >
        <input
          className="input num"
          type="text"
          inputMode="numeric"
          autoComplete="off"
          placeholder="Or type the barcode number"
          aria-label="Barcode number"
          value={typed}
          onChange={(e) => setTyped(e.target.value)}
        />
        <button
          type="submit"
          className="btn btn--ghost"
          disabled={typed.replace(/\D/g, '').length < 8}
        >
          Look up
        </button>
      </form>

      <button type="button" className="btn btn--quiet" onClick={onClose}>
        Cancel
      </button>
    </div>
  )
}

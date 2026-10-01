import { highlight } from './highlight'
import type { HighlightRequest } from './protocol'

self.onmessage = (event: MessageEvent<HighlightRequest>) => {
  self.postMessage(highlight(event.data))
}

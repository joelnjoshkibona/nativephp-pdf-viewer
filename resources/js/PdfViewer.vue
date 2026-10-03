<script setup lang="ts">
import axios from 'axios'
import { computed, nextTick, onBeforeUnmount, ref, shallowRef, watch } from 'vue'

type Renderer = 'auto' | 'native' | 'pdfjs'
type PdfSource = ArrayBuffer | Blob | ArrayBufferView

const props = withDefaults(defineProps<{
    open: boolean
    loadDocument: () => Promise<PdfSource>
    title?: string
    filename: string
    message?: string
    renderer?: Renderer
    endpoint?: string
    shareable?: boolean
}>(), {
    title: 'PDF document',
    message: '',
    renderer: 'auto',
    endpoint: '/native-pdf-viewer',
    shareable: true,
})

const emit = defineEmits<{
    'update:open': [open: boolean]
    loaded: [metadata: { pageCount: number; renderer: Exclude<Renderer, 'auto'> }]
    error: [error: Error]
}>()

const canvas = ref<HTMLCanvasElement | null>(null)
const loading = ref(false)
const sharing = ref(false)
const error = ref('')
const pageNumber = ref(1)
const pageCount = ref(0)
const pdfBytes = shallowRef<ArrayBuffer | null>(null)
const pdfDocument = shallowRef<any>(null)
const renderTask = shallowRef<any>(null)
const documentId = ref<string | null>(null)
const activeRenderer = ref<'native' | 'pdfjs' | null>(null)
const nativePageImage = ref('')
const canGoBack = computed(() => pageNumber.value > 1)
const canGoForward = computed(() => pageNumber.value < pageCount.value)

function localUrl(path: string): string {
    return `${window.location.origin}${props.endpoint.replace(/\/$/, '')}${path}`
}

function responseMessage(error: any, fallback: string): string {
    return error?.response?.data?.message || error?.message || fallback
}

function close(): void {
    emit('update:open', false)
}

function clearDocument(): void {
    renderTask.value?.cancel()
    void pdfDocument.value?.destroy()
    pdfDocument.value = null
    pdfBytes.value = null
    documentId.value = null
    nativePageImage.value = ''
    activeRenderer.value = null
    pageNumber.value = 1
    pageCount.value = 0
}

async function toArrayBuffer(source: PdfSource): Promise<ArrayBuffer> {
    if (source instanceof Blob) return source.arrayBuffer()
    if (source instanceof ArrayBuffer) return source
    if (ArrayBuffer.isView(source)) {
        return source.buffer.slice(source.byteOffset, source.byteOffset + source.byteLength) as ArrayBuffer
    }
    throw new Error('The PDF loader must return a Blob, ArrayBuffer, or typed array.')
}

async function encodePdf(): Promise<string> {
    if (!pdfBytes.value) throw new Error('No PDF is loaded.')
    return new Promise((resolve, reject) => {
        const reader = new FileReader()
        reader.onload = () => {
            if (typeof reader.result !== 'string') {
                reject(new Error('Could not prepare the PDF for the device.'))
                return
            }
            resolve(reader.result.slice(reader.result.indexOf(',') + 1))
        }
        reader.onerror = () => reject(new Error('Could not prepare the PDF for the device.'))
        reader.readAsDataURL(new Blob([pdfBytes.value], { type: 'application/pdf' }))
    })
}

async function stageDocument(renderer: Renderer): Promise<void> {
    if (documentId.value) return
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
    const response = await axios.post(localUrl('/documents'), {
        pdf_base64: await encodePdf(),
        filename: props.filename,
        renderer,
    }, {
        headers: { Accept: 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) },
    })
    documentId.value = response.data.id
    activeRenderer.value = response.data.renderer
    if (response.data.page_count) pageCount.value = Number(response.data.page_count)
}

async function loadPdfJs(): Promise<void> {
    const [{ GlobalWorkerOptions, getDocument }, worker] = await Promise.all([
        import('pdfjs-dist'),
        import('pdfjs-dist/build/pdf.worker.min.mjs?url'),
    ])
    GlobalWorkerOptions.workerSrc = worker.default
    if (!pdfBytes.value) throw new Error('No PDF is loaded.')
    // PDF.js transfers typed-array buffers to its worker, so render from a copy.
    pdfDocument.value = await getDocument({ data: new Uint8Array(pdfBytes.value).slice() }).promise
    activeRenderer.value = 'pdfjs'
    pageCount.value = pdfDocument.value.numPages
}

async function renderPdfJsPage(): Promise<void> {
    if (!pdfDocument.value || !canvas.value) return
    renderTask.value?.cancel()
    const page = await pdfDocument.value.getPage(pageNumber.value)
    const unscaled = page.getViewport({ scale: 1 })
    const availableWidth = canvas.value.parentElement?.clientWidth || 360
    const scale = Math.min(availableWidth / unscaled.width, 2)
    const viewport = page.getViewport({ scale: Math.max(scale, 0.5) })
    const outputScale = Math.min(window.devicePixelRatio || 1, 2)
    canvas.value.width = Math.floor(viewport.width * outputScale)
    canvas.value.height = Math.floor(viewport.height * outputScale)
    canvas.value.style.width = `${Math.floor(viewport.width)}px`
    canvas.value.style.height = `${Math.floor(viewport.height)}px`
    const context = canvas.value.getContext('2d')
    if (!context) throw new Error('This device could not create a PDF preview canvas.')
    const task = page.render({
        canvasContext: context,
        viewport,
        transform: outputScale === 1 ? null : [outputScale, 0, 0, outputScale, 0, 0],
    })
    renderTask.value = task
    await task.promise
    renderTask.value = null
}

async function renderNativePage(): Promise<void> {
    if (!documentId.value) throw new Error('The PDF has not been staged for native rendering.')
    const response = await axios.get(localUrl(`/documents/${documentId.value}/pages/${pageNumber.value}`), {
        headers: { Accept: 'application/json' },
    })
    nativePageImage.value = response.data.image
    pageCount.value = Number(response.data.page_count)
}

async function load(): Promise<void> {
    clearDocument()
    loading.value = true
    error.value = ''
    try {
        const source = await props.loadDocument()
        pdfBytes.value = await toArrayBuffer(source)

        if (props.renderer !== 'pdfjs') {
            await stageDocument(props.renderer)
            if (activeRenderer.value === 'native') {
                await renderNativePage()
            } else if (props.renderer === 'native') {
                throw new Error('Native PDF rendering is unavailable for this document.')
            } else {
                await loadPdfJs()
            }
        } else {
            await loadPdfJs()
        }

        if (activeRenderer.value === 'pdfjs') {
            // The canvas only exists after the loading branch leaves the DOM.
            loading.value = false
            await nextTick()
            await renderPdfJsPage()
        }

        emit('loaded', { pageCount: pageCount.value, renderer: activeRenderer.value || 'pdfjs' })
    } catch (cause: any) {
        error.value = responseMessage(cause, 'Could not open this PDF.')
        emit('error', cause instanceof Error ? cause : new Error(error.value))
    } finally {
        loading.value = false
    }
}

async function changePage(direction: -1 | 1): Promise<void> {
    if (loading.value || sharing.value) return
    pageNumber.value += direction
    error.value = ''
    try {
        if (activeRenderer.value === 'native') await renderNativePage()
        else await renderPdfJsPage()
    } catch (cause: any) {
        error.value = responseMessage(cause, 'Could not display this PDF page.')
        emit('error', cause instanceof Error ? cause : new Error(error.value))
    }
}

async function share(): Promise<void> {
    if (!props.shareable || sharing.value || !pdfBytes.value) return
    sharing.value = true
    error.value = ''
    try {
        if (!documentId.value) await stageDocument('pdfjs')
        const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
        await axios.post(localUrl(`/documents/${documentId.value}/share`), {
            title: props.title,
            message: props.message,
        }, {
            headers: { Accept: 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) },
        })
    } catch (cause: any) {
        error.value = responseMessage(cause, 'Could not share this PDF.')
        emit('error', cause instanceof Error ? cause : new Error(error.value))
    } finally {
        sharing.value = false
    }
}

watch(() => props.open, (open) => {
    if (open) void load()
    else clearDocument()
}, { immediate: true })

watch(() => props.filename, () => {
    if (props.open) void load()
})

onBeforeUnmount(clearDocument)

defineExpose({ reload: load, share, goToPage: async (page: number) => {
    if (page < 1 || page > pageCount.value) return
    pageNumber.value = page
    if (activeRenderer.value === 'native') await renderNativePage()
    else await renderPdfJsPage()
} })
</script>

<template>
    <div v-if="open" class="npv-root" role="dialog" aria-modal="true" :aria-label="title">
        <header class="npv-header">
            <button type="button" class="npv-icon-button" aria-label="Close PDF preview" @click="close">×</button>
            <div class="npv-heading"><strong>{{ filename }}</strong><span>{{ title }}</span></div>
            <button v-if="shareable" type="button" class="npv-share-button" :disabled="loading || sharing || !pdfBytes" @click="share">
                {{ sharing ? 'Preparing…' : 'Share' }}
            </button>
        </header>

        <nav v-if="pageCount > 1" class="npv-pages" aria-label="PDF pages">
            <button type="button" :disabled="!canGoBack || loading" aria-label="Previous page" @click="changePage(-1)">‹</button>
            <span>Page {{ pageNumber }} of {{ pageCount }}</span>
            <button type="button" :disabled="!canGoForward || loading" aria-label="Next page" @click="changePage(1)">›</button>
        </nav>

        <main class="npv-content">
            <p v-if="loading" class="npv-message" role="status">Loading PDF…</p>
            <div v-else-if="error && !pdfBytes" class="npv-error" role="alert">
                <p>{{ error }}</p><button type="button" @click="load">Try again</button>
            </div>
            <div v-else-if="error" class="npv-error npv-inline-error" role="alert">{{ error }}</div>
            <div v-else-if="activeRenderer === 'native'" class="npv-page">
                <img :src="nativePageImage" alt="PDF page preview"/>
            </div>
            <div v-else class="npv-page"><canvas ref="canvas"/></div>
        </main>
    </div>
</template>

<style scoped>
.npv-root { position: fixed; inset: 0; z-index: 1000; display: flex; flex-direction: column; color: var(--foreground, #18262b); background: var(--background, #f2f4f1); color-scheme: inherit; font: 15px/1.45 system-ui, sans-serif; }
.npv-header { display: flex; align-items: center; gap: 12px; flex: 0 0 auto; padding: calc(env(safe-area-inset-top) + 12px) 16px 12px; background: var(--card, #fff); border-bottom: 1px solid var(--border, #dfe5df); }
.npv-icon-button, .npv-pages button { width: 40px; height: 40px; border: 1px solid var(--border, #dfe5df); border-radius: 999px; background: var(--card, #fff); color: var(--card-foreground, var(--foreground, #18262b)); font-size: 24px; }
.npv-heading { display: flex; flex: 1; min-width: 0; flex-direction: column; }
.npv-heading strong { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.npv-heading span, .npv-message { color: var(--muted-foreground, #66746f); font-size: 12px; }
.npv-share-button { min-height: 40px; padding: 0 16px; border: 0; border-radius: 12px; background: var(--primary, #176b3a); color: var(--primary-foreground, #fff); font-weight: 650; }
.npv-share-button:disabled { opacity: .55; }
.npv-pages { display: flex; align-items: center; justify-content: center; gap: 22px; padding: 8px; background: var(--card, #fff); border-bottom: 1px solid var(--border, #dfe5df); }
.npv-pages button { font-size: 28px; line-height: 1; }
.npv-pages button:disabled { opacity: .4; }
.npv-content { display: flex; flex: 1; min-height: 0; justify-content: center; overflow: auto; padding: 12px 12px calc(env(safe-area-inset-bottom) + 16px); background: var(--muted, var(--background, #f2f4f1)); }
.npv-page { width: 100%; overflow: auto; text-align: center; }
.npv-page canvas, .npv-page img { display: block; max-width: 100%; height: auto; margin: 0 auto; background: #fff; box-shadow: 0 2px 12px rgb(0 0 0 / 12%); }
.npv-message { align-self: flex-start; padding: 24px; }
.npv-error { align-self: flex-start; width: 100%; padding: 14px; border: 1px solid var(--destructive, #b42318); border-radius: 12px; background: var(--card, #fff); color: var(--destructive, #b42318); }
.npv-error button { margin-top: 10px; border: 0; background: transparent; color: inherit; font-weight: 700; text-decoration: underline; }
.npv-inline-error { align-self: flex-start; }
</style>

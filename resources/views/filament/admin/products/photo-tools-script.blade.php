{{-- Alpine component for photo-tools.blade.php. Loaded on All Products only
     (SCRIPTS_BEFORE, scoped in AdminPanelProvider). --}}
<script>
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('gpPhotoTools', () => ({
            // The upload field's schema key, for calling its methods.
            fieldKey: null,
            upload: null,
            retry: null,

            // Drawn rather than ◀ ▶, which some phones render as emoji.
            arrows: {
                earlier: '<svg viewBox="0 0 20 20" width="20" height="20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M12.8 4.2a.75.75 0 0 1 0 1.06L8.06 10l4.74 4.74a.75.75 0 1 1-1.06 1.06l-5.27-5.27a.75.75 0 0 1 0-1.06l5.27-5.27a.75.75 0 0 1 1.06 0Z"/></svg>',
                later: '<svg viewBox="0 0 20 20" width="20" height="20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.2 15.8a.75.75 0 0 1 0-1.06L11.94 10 7.2 5.26A.75.75 0 1 1 8.26 4.2l5.27 5.27a.75.75 0 0 1 0 1.06L8.26 15.8a.75.75 0 0 1-1.06 0Z"/></svg>',
            },

            init() {
                this.fieldKey = this.$root.dataset.fieldKey
                this.attach()
            },

            destroy() {
                clearTimeout(this.retry)
            },

            // The upload field's own Alpine component; its FilePond starts
            // asynchronously, so wait for it.
            attach() {
                const element = this.$root.closest('.fi-modal-window')?.querySelector('.fi-fo-file-upload')
                const upload = element ? window.Alpine.$data(element) : null

                if (! upload?.pond) {
                    this.retry = setTimeout(() => this.attach(), 250)

                    return
                }

                this.upload = upload

                // FilePond draws a new item a frame after announcing it.
                for (const event of ['init', 'addfile', 'removefile', 'reorderfiles', 'updatefiles', 'processfile']) {
                    upload.pond.on(event, () => setTimeout(() => this.decorate(), 60))
                }

                this.decorate()
            },

            takePhoto() {
                // A separate input: the capture attribute on FilePond's own one
                // would stop "Choose from gallery" offering the gallery.
                const input = document.createElement('input')
                input.type = 'file'
                input.accept = 'image/*'
                input.setAttribute('capture', 'environment')
                input.hidden = true
                input.addEventListener('change', () => {
                    if (input.files.length) {
                        this.upload?.pond.addFiles([...input.files])
                    }

                    input.remove()
                })

                this.$root.appendChild(input)
                input.click()
            },

            chooseFromGallery() {
                this.upload?.pond.browse()
            },

            // Keys in on-screen order, worked out exactly as the upload
            // field's own reorderfiles handler does it.
            orderedKeys() {
                return this.upload.pond.getFiles()
                    .map((file) => file.source instanceof File
                        ? file.serverId
                        : (this.upload.uploadedFileIndex[file.source] ?? null))
                    .filter((key) => key)
            },

            async move(file, offset) {
                const keys = this.orderedKeys()
                const key = file.source instanceof File ? file.serverId : this.upload.uploadedFileIndex[file.source]
                const from = keys.indexOf(key)

                if (from === -1) {
                    return
                }

                const to = offset === 'first' ? 0 : Math.min(Math.max(from + offset, 0), keys.length - 1)

                keys.splice(to, 0, ...keys.splice(from, 1))

                // Both halves of a drag: FilePond moves the tile (reloading its
                // files would not — it keeps the tiles it already has where they
                // are), and the field's state takes the new order for Save.
                this.upload.pond.moveFile(file.id, to)
                setTimeout(() => this.decorate(), 60)

                await this.$wire.callSchemaComponentMethod(this.fieldKey,'reorderUploadedFiles', { fileKeys: keys })
            },

            // "Main photo" on the first tile; move buttons on every tile.
            decorate() {
                const files = this.upload?.pond.getFiles() ?? []

                files.forEach((file, index) => {
                    const item = document.getElementById(`filepond--item-${file.id}`)

                    if (! item) {
                        return
                    }

                    let tools = item.querySelector(':scope > .gp-photo-tools')

                    if (! tools) {
                        tools = document.createElement('div')
                        tools.className = 'gp-photo-tools'
                        // Keep FilePond's drag-to-reorder from starting under a tap.
                        tools.addEventListener('pointerdown', (event) => event.stopPropagation())
                        item.appendChild(tools)
                    }

                    tools.replaceChildren()
                    item.toggleAttribute('data-gp-main', index === 0)

                    const button = (label, title, action) => {
                        const element = document.createElement('button')
                        element.type = 'button'

                        if (label in this.arrows) {
                            element.innerHTML = this.arrows[label]
                        } else {
                            element.textContent = label
                        }

                        element.title = title
                        element.setAttribute('aria-label', title)
                        element.addEventListener('click', (event) => {
                            event.preventDefault()
                            event.stopPropagation()
                            action()
                        })
                        tools.appendChild(element)
                    }

                    if (index > 0) {
                        button('earlier', 'Move earlier', () => this.move(file, -1))
                    }

                    if (index === 0) {
                        const badge = document.createElement('span')
                        badge.className = 'gp-photo-main'
                        badge.textContent = 'Main photo'
                        tools.appendChild(badge)
                    } else {
                        button('Make main', 'Make this the main photo', () => this.move(file, 'first'))
                    }

                    if (index < files.length - 1) {
                        button('later', 'Move later', () => this.move(file, 1))
                    }
                })
            },
        }))
    })
</script>

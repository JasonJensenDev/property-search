import Alpine from 'alpinejs';

/**
 * State for the review screen: photo browsing, the full-screen viewer, and the keyboard
 * shortcuts that make working through a long queue quick.
 */
Alpine.data('reviewScreen', ({ photoCount = 0, nextUrl = null, previousUrl = null }) => ({
    photos: [],
    index: 0,
    lightbox: false,
    askReason: false,
    showHelp: false,
    reasonCode: '',
    reasonText: '',

    init() {
        const data = document.getElementById('photo-data');
        this.photos = data ? JSON.parse(data.textContent) : [];

        // Warm the next few images so paging through a gallery does not flash.
        this.photos.slice(0, 4).forEach((photo) => {
            const image = new Image();
            image.src = photo.url;
        });
    },

    next() {
        if (photoCount) this.index = (this.index + 1) % photoCount;
    },

    prev() {
        if (photoCount) this.index = (this.index - 1 + photoCount) % photoCount;
    },

    submitDecision(decision) {
        const form = this.$refs.decisionForm;

        // Set the field directly rather than through x-model. Alpine flushes bindings on
        // a microtask, which lands *after* a synchronous submit(), so the form would post
        // an empty decision and the server would reject it.
        form.querySelector('input[name="decision"]').value = decision;

        this.askReason = false;
        form.submit();
    },

    onKey(event) {
        // Never hijack keys while the user is writing a note or a reason.
        const tag = event.target.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || event.target.isContentEditable) {
            if (event.key === 'Escape') event.target.blur();
            return;
        }

        if (event.metaKey || event.ctrlKey || event.altKey) return;

        switch (event.key) {
            case 'ArrowRight':
                event.preventDefault();
                this.next();
                break;
            case 'ArrowLeft':
                event.preventDefault();
                this.prev();
                break;
            case 'Enter':
                event.preventDefault();
                this.lightbox = true;
                break;
            case 'Escape':
                if (this.lightbox) this.lightbox = false;
                else if (this.askReason) this.askReason = false;
                else if (this.showHelp) this.showHelp = false;
                break;
            case 'f':
            case 'F':
                event.preventDefault();
                this.submitDecision('favorite');
                break;
            case 'm':
            case 'M':
                event.preventDefault();
                this.submitDecision('maybe');
                break;
            case 'x':
            case 'X':
                event.preventDefault();
                this.askReason = true;
                // Focus the reason list so a preset can be picked immediately.
                this.$nextTick(() => this.$el.querySelector('[name="reason_code"]')?.focus());
                break;
            case 's':
            case 'S':
                if (nextUrl) {
                    event.preventDefault();
                    window.location.href = nextUrl;
                }
                break;
            case 'p':
            case 'P':
                if (previousUrl) {
                    event.preventDefault();
                    window.location.href = previousUrl;
                }
                break;
            case 'o':
            case 'O':
                event.preventDefault();
                this.$refs.sourceLink?.click();
                break;
        }
    },
}));

/**
 * Polls the scrape status so the dashboard shows progress without a manual refresh.
 */
Alpine.data('scrapeMonitor', ({ statusUrl }) => ({
    run: null,
    polling: false,

    init() {
        this.load();
    },

    async load() {
        try {
            const response = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
            this.run = await response.json();
        } catch {
            return;
        }

        if (this.run?.status === 'running') {
            this.polling = true;
            setTimeout(() => this.load(), 3000);
        } else if (this.polling) {
            // The run just finished, so pull in the new numbers.
            this.polling = false;
            window.location.reload();
        }
    },
}));

window.Alpine = Alpine;
Alpine.start();

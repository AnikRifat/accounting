// Searchable select behind x-form.select. Options are server-rendered <li> elements, so Livewire
// morphs them like any other markup; a MutationObserver bumps `revision` so the label stays current.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('searchSelect', () => ({
        value: '',
        open: false,
        query: '',
        active: null,
        revision: 0,
        observer: null,

        init() {
            this.observer = new MutationObserver(() => this.revision++);
            this.observer.observe(this.$refs.list, { childList: true, subtree: true, characterData: true });
        },

        destroy() {
            this.observer?.disconnect();
        },

        items() {
            this.revision;

            return Array.from(this.$refs.list.querySelectorAll('[role=option]'));
        },

        current() {
            return String(this.value ?? '');
        },

        selectedLabel() {
            const item = this.items().find((el) => el.dataset.value === this.current());

            return item ? item.textContent.trim() : this.$refs.list.dataset.placeholder;
        },

        matches(el) {
            const needle = this.query.trim().toLowerCase();

            return needle === '' || el.textContent.toLowerCase().includes(needle);
        },

        visibleItems() {
            return this.items().filter((el) => this.matches(el));
        },

        hasMatches() {
            return this.visibleItems().length > 0;
        },

        isSelected(el) {
            return el.dataset.value === this.current();
        },

        isActive(el) {
            return el.dataset.value === this.active;
        },

        activeId() {
            return this.visibleItems().find((el) => this.isActive(el))?.id ?? null;
        },

        show() {
            if (this.$refs.trigger.disabled) {
                return;
            }
            this.query = '';
            this.active = this.current();
            this.open = true;
            this.$nextTick(() => {
                this.$refs.search.focus();
                this.scrollToActive();
            });
        },

        close(refocus = false) {
            if (!this.open) {
                return;
            }
            this.open = false;
            if (refocus) {
                this.$refs.trigger.focus();
            }
        },

        toggle() {
            this.open ? this.close(true) : this.show();
        },

        closeOnFocusOut(event) {
            if (!this.$root.contains(event.relatedTarget)) {
                this.close();
            }
        },

        activate(el) {
            this.active = el.dataset.value;
        },

        activateFirst() {
            this.active = this.visibleItems()[0]?.dataset.value ?? null;
        },

        move(step) {
            const visible = this.visibleItems();
            if (visible.length === 0) {
                return;
            }
            const index = visible.findIndex((el) => this.isActive(el));
            const next = index === -1 ? 0 : Math.min(Math.max(index + step, 0), visible.length - 1);
            this.active = visible[next].dataset.value;
            this.scrollToActive();
        },

        scrollToActive() {
            this.$nextTick(() => this.visibleItems().find((el) => this.isActive(el))?.scrollIntoView({ block: 'nearest' }));
        },

        choose(el) {
            this.value = el.dataset.value;
            this.close(true);
        },

        chooseActive() {
            const el = this.visibleItems().find((item) => this.isActive(item));
            if (el) {
                this.choose(el);
            }
        },
    }));
});

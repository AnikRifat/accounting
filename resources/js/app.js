import imageUpload from './image-upload';
import chartComponent from './chart';

// Searchable select behind x-form.select. Options are server-rendered <li> elements, so Livewire
// morphs them like any other markup; a MutationObserver bumps `revision` so the label stays current.
// Render-time lookups go through $root, not $refs: during a morph Alpine evaluates x-text on the incoming
// markup before the list's x-ref is registered there, and a throw would leave the label blank.
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

            return Array.from(this.$root.querySelectorAll('[role=option]'));
        },

        current() {
            return String(this.value ?? '');
        },

        selectedLabel() {
            const item = this.items().find((el) => el.dataset.value === this.current());

            return item ? item.textContent.trim() : this.$root.dataset.placeholder;
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

// Calendar maths shared by the date and range pickers. Dates travel as 'YYYY-MM-DD' strings, the format
// Livewire properties and the server validate, and are built in local time so no timezone shift creeps in.
const pad = (n) => String(n).padStart(2, '0');
const toIso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const fromIso = (s) => {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s ?? '');

    return m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : null;
};
const addDays = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
const formatDay = (s) => {
    const d = fromIso(s);

    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    // Same shape as the server's 'd M Y', e.g. "24 Sep 2026".
    return d ? `${pad(d.getDate())} ${months[d.getMonth()]} ${d.getFullYear()}` : '';
};
const calendar = {
    viewYear: 0,
    viewMonth: 0,
    focused: '',

    monthLabel() {
        return new Date(this.viewYear, this.viewMonth, 1).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
    },
    weekdays() {
        return ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
    },
    days() {
        const first = new Date(this.viewYear, this.viewMonth, 1);
        const start = addDays(first, -first.getDay());

        return Array.from({ length: 42 }, (_, i) => {
            const d = addDays(start, i);

            return { iso: toIso(d), day: d.getDate(), inMonth: d.getMonth() === this.viewMonth, today: toIso(d) === toIso(new Date()) };
        });
    },
    shiftMonth(step) {
        const d = new Date(this.viewYear, this.viewMonth + step, 1);
        this.viewYear = d.getFullYear();
        this.viewMonth = d.getMonth();
    },
    showMonthOf(iso) {
        const d = fromIso(iso) ?? new Date();
        this.viewYear = d.getFullYear();
        this.viewMonth = d.getMonth();
        this.focused = toIso(d);
    },
    moveFocus(days) {
        const d = addDays(fromIso(this.focused) ?? new Date(), days);
        this.focused = toIso(d);
        if (d.getMonth() !== this.viewMonth || d.getFullYear() !== this.viewYear) {
            this.showMonthOf(this.focused);
        }
        this.$nextTick(() => this.$root.querySelector(`[data-day="${this.focused}"]`)?.focus());
    },
    focusGrid() {
        this.$nextTick(() => (this.$root.querySelector(`[data-day="${this.focused}"]`) ?? this.$root.querySelector('[data-day]'))?.focus());
    },
};

document.addEventListener('alpine:init', () => {
    // Single date behind x-form.date, bound with wire:model / x-model through x-modelable="value".
    window.Alpine.data('datePicker', () => ({
        ...calendar,
        value: '',
        open: false,
        hover: '',

        label() {
            return formatDay(this.value);
        },
        toggle() {
            this.open ? this.close(true) : this.show();
        },
        show() {
            if (this.$refs.trigger.disabled) {
                return;
            }
            this.showMonthOf(this.value);
            this.open = true;
            this.focusGrid();
        },
        close(refocus = false) {
            this.open = false;
            if (refocus) {
                this.$refs.trigger.focus();
            }
        },
        pick(iso) {
            this.value = iso;
            this.close(true);
        },
        isSelected(iso) {
            return iso === this.value;
        },
        inRange() {
            return false;
        },
    }));

    // From/to range behind x-form.date-range: presets on the side, one month grid, click start then end.
    window.Alpine.data('dateRange', ({ from, to }) => ({
        ...calendar,
        from,
        to,
        open: false,
        anchor: '',
        hover: '',

        label() {
            if (!this.from && !this.to) {
                return '';
            }
            if (this.from === this.to) {
                return formatDay(this.from);
            }

            return `${formatDay(this.from) || '…'} – ${formatDay(this.to) || '…'}`;
        },
        presets() {
            const today = new Date();
            const y = today.getFullYear();
            const m = today.getMonth();

            return [
                ['today', toIso(today), toIso(today)],
                ['yesterday', toIso(addDays(today, -1)), toIso(addDays(today, -1))],
                ['last7', toIso(addDays(today, -6)), toIso(today)],
                ['last30', toIso(addDays(today, -29)), toIso(today)],
                ['thisMonth', toIso(new Date(y, m, 1)), toIso(new Date(y, m + 1, 0))],
                ['lastMonth', toIso(new Date(y, m - 1, 1)), toIso(new Date(y, m, 0))],
                ['thisYear', toIso(new Date(y, 0, 1)), toIso(new Date(y, 11, 31))],
            ];
        },
        isPreset([, start, end]) {
            return this.from === start && this.to === end;
        },
        applyPreset([, start, end]) {
            this.set(start, end);
            this.close(true);
        },
        set(start, end) {
            this.anchor = '';
            this.from = start;
            this.to = end;
        },
        clear() {
            this.set('', '');
            this.close(true);
        },
        toggle() {
            this.open ? this.close(true) : this.show();
        },
        show() {
            this.anchor = '';
            this.showMonthOf(this.from || this.to);
            this.open = true;
            this.focusGrid();
        },
        close(refocus = false) {
            this.open = false;
            if (refocus) {
                this.$refs.trigger.focus();
            }
        },
        pick(iso) {
            if (!this.anchor) {
                this.anchor = iso;
                this.hover = iso;

                return;
            }
            const [start, end] = [this.anchor, iso].sort();
            this.set(start, end);
            this.close(true);
        },
        bounds() {
            if (this.anchor) {
                return [this.anchor, this.hover || this.anchor].sort();
            }

            return [this.from, this.to];
        },
        isSelected(iso) {
            const [start, end] = this.bounds();

            return iso === start || iso === end;
        },
        inRange(iso) {
            const [start, end] = this.bounds();

            return Boolean(start && end) && iso > start && iso < end;
        },
    }));
});

document.addEventListener('alpine:init', () => {
    // Export & print options behind x-table.export; the work happens in WithTableTools::exportTable().
    window.Alpine.data('tableExport', (keys) => ({
        keys,
        picked: [...keys],
        visible: false,
        busy: false,
        format: 'xlsx',
        scope: 'all',
        orientation: 'portrait',

        show(scope) {
            this.scope = scope === 'selected' && this.$wire.selected.length ? 'selected' : 'all';
            this.visible = true;
        },
        async run(csvUrl) {
            if (this.format === 'csv') {
                window.location.href = csvUrl;
                this.visible = false;

                return;
            }
            this.busy = true;
            try {
                await this.$wire.exportTable(this.format, this.scope, this.picked, this.orientation);
                this.visible = false;
            } finally {
                this.busy = false;
            }
        },
    }));
});

// A prepared print page (see TableExport::toPrint) is printed from a hidden frame, so no pop-up is needed.
window.addEventListener('print-table', (event) => {
    const frame = document.createElement('iframe');
    frame.setAttribute('aria-hidden', 'true');
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
    frame.src = event.detail.url;
    frame.addEventListener('load', () => {
        frame.contentWindow.focus();
        frame.contentWindow.print();
        setTimeout(() => frame.remove(), 60000);
    });
    document.body.appendChild(frame);
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('imageUpload', imageUpload);
    window.Alpine.data('chart', chartComponent);
});

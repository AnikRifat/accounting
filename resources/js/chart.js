import { Chart, registerables } from 'chart.js';

Chart.register(...registerables);

// Bangladeshi currency formatting (lakh / crore)
export function formatMoney(paisa, compact = false) {
    if (paisa === null || paisa === undefined || isNaN(paisa)) {
        return '৳0';
    }
    const isNegative = paisa < 0;
    const absPaisa = Math.abs(Number(paisa));
    const taka = Math.floor(absPaisa / 100);

    if (compact) {
        if (taka >= 10000000) {
            return `${isNegative ? '-' : ''}৳${(taka / 10000000).toFixed(2).replace(/\.00$/, '')} Cr`;
        }
        if (taka >= 100000) {
            return `${isNegative ? '-' : ''}৳${(taka / 100000).toFixed(2).replace(/\.00$/, '')} L`;
        }
        if (taka >= 1000) {
            return `${isNegative ? '-' : ''}৳${(taka / 1000).toFixed(1).replace(/\.0$/, '')}k`;
        }
    }

    const digits = String(taka);
    const grouped = digits.length > 3
        ? digits.slice(0, -3).replace(/\B(?=(\d{2})+$)/g, ',') + ',' + digits.slice(-3)
        : digits;

    return `${isNegative ? '-' : ''}৳${grouped}`;
}

export default function chartComponent(cfg = {}) {
    return {
        chartInstance: null,

        init() {
            this.$nextTick(() => {
                this.render(cfg);
            });
        },

        destroy() {
            if (this.chartInstance) {
                this.chartInstance.destroy();
                this.chartInstance = null;
            }
        },

        render(config) {
            const canvas = this.$refs.canvas || this.$el.querySelector('canvas');
            if (!canvas) return;

            if (this.chartInstance) {
                this.chartInstance.destroy();
                this.chartInstance = null;
            }

            const type = config.type || 'bar';
            const data = config.data || { labels: [], datasets: [] };
            const isMoney = config.isMoney ?? true;
            const isHorizontal = config.horizontal ?? false;

            const defaultOptions = {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 350,
                    easing: 'easeOutQuart',
                },
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        display: config.legend ?? (type === 'doughnut' || type === 'pie' || (data.datasets && data.datasets.length > 1)),
                        position: config.legendPosition ?? (type === 'doughnut' ? 'bottom' : 'top'),
                        labels: {
                            font: { family: 'Geist, system-ui, sans-serif', size: 12, weight: '500' },
                            color: '#475569',
                            boxWidth: 12,
                            boxHeight: 12,
                            borderRadius: 3,
                            useBorderRadius: true,
                            padding: 14,
                        },
                    },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleColor: '#f8fafc',
                        bodyColor: '#cbd5e1',
                        borderColor: '#334155',
                        borderWidth: 1,
                        padding: 10,
                        cornerRadius: 8,
                        titleFont: { family: 'Geist, system-ui, sans-serif', size: 12, weight: '600' },
                        bodyFont: { family: 'Geist, system-ui, sans-serif', size: 12 },
                        boxPadding: 4,
                        callbacks: {
                            label: (context) => {
                                const label = context.dataset.label || context.label || '';
                                const raw = context.raw;
                                const val = isMoney ? formatMoney(raw) : raw;
                                return ` ${label}: ${val}`;
                            },
                        },
                    },
                },
            };

            if (type !== 'doughnut' && type !== 'pie') {
                const valueAxis = isHorizontal ? 'x' : 'y';
                const catAxis = isHorizontal ? 'y' : 'x';

                defaultOptions.scales = {
                    [catAxis]: {
                        grid: { display: false },
                        ticks: {
                            font: { family: 'Geist, system-ui, sans-serif', size: 12 },
                            color: '#64748b',
                        },
                        border: { color: '#e2e8f0' },
                    },
                    [valueAxis]: {
                        grid: {
                            color: '#f1f5f9',
                            drawBorder: false,
                        },
                        ticks: {
                            font: { family: 'Geist, system-ui, sans-serif', size: 11 },
                            color: '#64748b',
                            callback: (val) => (isMoney ? formatMoney(val, true) : val),
                        },
                        border: { display: false },
                    },
                };
            } else {
                defaultOptions.cutout = config.cutout || '70%';
            }

            const options = Object.assign({}, defaultOptions, config.options || {});

            this.chartInstance = new Chart(canvas.getContext('2d'), {
                type,
                data,
                options,
            });
        },
    };
}

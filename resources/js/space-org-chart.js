import { OrgChart } from 'd3-org-chart';

const charts = new WeakMap();

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
}[character]));

const parseLabels = (container) => {
    try {
        return JSON.parse(container.dataset.labels || '{}');
    } catch {
        return {};
    }
};

const nodeHtml = (data, labels) => {
    const items = Array.isArray(data.items) ? data.items : [];
    const root = data.kind === 'root';
    const classes = [
        'space-org-node',
        data.kind === 'composite' ? 'space-org-node--composite' : '',
        root ? 'space-org-node--root' : '',
    ].filter(Boolean).join(' ');

    const itemRows = items.map((item) => `
        <li>
            <span>${escapeHtml(item.name)}</span>
            <span class="space-org-node__state">${escapeHtml(item.stateLabel || '')}</span>
        </li>
    `).join('');

    const more = data.moreItems > 0
        ? `<div class="space-org-node__more">${escapeHtml(String(labels.more || '+:count').replace(':count', data.moreItems))}</div>`
        : '';

    const path = root
        ? ''
        : `<div class="space-org-node__path" title="${escapeHtml(data.path)}">${escapeHtml(data.path)}</div>`;

    return `
        <div class="${classes}">
            <div class="space-org-node__name">${escapeHtml(data.name)}</div>
            ${path}
            <div class="space-org-node__meta">
                <span>${escapeHtml(String(data.directItems))} ${escapeHtml(labels.direct || 'directos')}</span>
                <span>·</span>
                <span>${escapeHtml(String(data.totalItems))} ${escapeHtml(labels.total || 'en el subárbol')}</span>
            </div>
            <ul class="space-org-node__items">${itemRows}</ul>
            ${more}
        </div>
    `;
};

const bindControls = (container, chart) => {
    const root = container.closest('[data-org-chart-root]');

    if (!root || root.dataset.orgChartControlsBound === '1') {
        return;
    }

    root.dataset.orgChartControlsBound = '1';

    const actions = {
        'zoom-in': () => chart.zoomIn(),
        'zoom-out': () => chart.zoomOut(),
        'fit': () => chart.fit(),
        'expand-all': () => chart.expandAll(),
        'collapse-all': () => chart.collapseAll(),
    };

    root.querySelectorAll('[data-org-chart-action]').forEach((button) => {
        button.addEventListener('click', () => {
            const action = actions[button.dataset.orgChartAction];

            if (action) {
                action();
            }
        });
    });
};

const initChart = (container) => {
    if (charts.has(container)) {
        charts.get(container).fit();

        return;
    }

    const dataElement = document.getElementById(container.dataset.chartData || '');

    if (!dataElement) {
        return;
    }

    let data;

    try {
        data = JSON.parse(dataElement.textContent || '[]');
    } catch {
        return;
    }

    if (!Array.isArray(data) || data.length === 0) {
        return;
    }

    const labels = parseLabels(container);

    const chart = new OrgChart()
        .container(container)
        .data(data)
        // La libreria toma svgHeight de window.innerHeight, no del contenedor.
        // Sin esto, fit() calcula el zoom contra una altura mayor y el arbol
        // queda recortado dentro del panel.
        .svgHeight(container.clientHeight || 640)
        // d3-org-chart v3 evalua estas opciones como funciones, no como
        // constantes. Pasar un numero hace que falle en render().
        .nodeWidth(() => 280)
        .nodeHeight(() => 150)
        .childrenMargin(() => 55)
        .compactMarginBetween(() => 24)
        .compactMarginPair(() => 90)
        .siblingsMargin(() => 24)
        .nodeContent(({ data: node }) => nodeHtml(node, labels))
        .render();

    charts.set(container, chart);
    bindControls(container, chart);

    requestAnimationFrame(() => chart.fit());
};

const bootCharts = () => {
    document.querySelectorAll('[data-space-org-chart]').forEach(initChart);
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootCharts, { once: true });
} else {
    bootCharts();
}

document.addEventListener('livewire:navigated', () => {
    requestAnimationFrame(bootCharts);
});

let resizeTimer = null;

window.addEventListener('resize', () => {
    window.clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(() => {
        document.querySelectorAll('[data-space-org-chart]').forEach((container) => {
            if (container.offsetParent !== null) {
                charts
                    .get(container)
                    ?.svgHeight(container.clientHeight || 640)
                    .fit();
            }
        });
    }, 180);
});

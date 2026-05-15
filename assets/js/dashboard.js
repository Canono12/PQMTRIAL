// PQM Dashboard — Global JS

// Default Chart.js theme
const PQM_COLORS = {
    blue:   '#3b82f6',
    teal:   '#0ea5e9',
    green:  '#22c55e',
    amber:  '#f59e0b',
    red:    '#ef4444',
    purple: '#a855f7',
    grid:   'rgba(255,255,255,0.06)',
    text:   '#94a3b8',
};

// Apply dark defaults to all charts
Chart.defaults.color          = PQM_COLORS.text;
Chart.defaults.borderColor    = PQM_COLORS.grid;
Chart.defaults.font.family    = "'Segoe UI', system-ui, sans-serif";
Chart.defaults.font.size      = 12;
Chart.defaults.plugins.legend.labels.color = PQM_COLORS.text;
Chart.defaults.plugins.tooltip.backgroundColor = '#1a3358';
Chart.defaults.plugins.tooltip.titleColor      = '#f1f5f9';
Chart.defaults.plugins.tooltip.bodyColor       = '#94a3b8';
Chart.defaults.plugins.tooltip.borderColor     = 'rgba(255,255,255,.1)';
Chart.defaults.plugins.tooltip.borderWidth     = 1;
Chart.defaults.plugins.tooltip.padding         = 10;
Chart.defaults.plugins.tooltip.cornerRadius    = 8;

// Helper: create a standard line dataset config
function lineDataset(label, data, color) {
    return {
        label,
        data,
        borderColor: color,
        backgroundColor: color + '22',
        borderWidth: 2,
        pointRadius: 3,
        pointHoverRadius: 6,
        tension: 0.4,
        fill: true,
    };
}

// Helper: create a standard bar dataset config
function barDataset(label, data, color) {
    return {
        label,
        data,
        backgroundColor: color + 'bb',
        borderColor: color,
        borderWidth: 1,
        borderRadius: 6,
    };
}

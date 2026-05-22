export const fmt = (amount) =>
    '₦' + Number(amount ?? 0).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export const generateOfflineId = () =>
    'OFF-' + Date.now() + '-' + Math.random().toString(36).substring(2, 8).toUpperCase();

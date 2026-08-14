import type { ProductDuration } from '@/components/ProductPicker.vue';

/**
 * Adds a product's recurring duration to a date, returning the result as a
 * `YYYY-MM-DD` string. Used to auto-fill an invoice row's expiration date
 * when a service product with a duration is applied to it.
 */
export function addDurationToDate(
    date: string,
    duration: ProductDuration,
): string {
    const [year, month, day] = date.split('-').map(Number);
    const result = new Date(Date.UTC(year, month - 1, day));

    switch (duration) {
        case 'weekly':
            result.setUTCDate(result.getUTCDate() + 7);
            break;
        case 'monthly':
            result.setUTCMonth(result.getUTCMonth() + 1);
            break;
        case 'yearly':
            result.setUTCFullYear(result.getUTCFullYear() + 1);
            break;
    }

    return result.toISOString().slice(0, 10);
}

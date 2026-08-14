export type EstimationStatus = 'pending' | 'accepted' | 'rejected';

export function estimationStatusBadgeVariant(
    status: EstimationStatus,
): 'default' | 'secondary' | 'destructive' {
    if (status === 'accepted') {
        return 'default';
    }

    if (status === 'rejected') {
        return 'destructive';
    }

    return 'secondary';
}

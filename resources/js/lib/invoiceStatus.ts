export type InvoiceStatus = 'draft' | 'issued';

export type SubmissionStatus =
    | 'pending'
    | 'failed'
    | 'submitted'
    | 'rejected'
    | 'accepted'
    | 'delivered'
    | 'not_delivered';

export function invoiceStatusVariant(
    status: InvoiceStatus,
): 'outline' | 'default' {
    return status === 'draft' ? 'outline' : 'default';
}

export function submissionStatusVariant(
    status: SubmissionStatus,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'failed':
        case 'rejected':
            return 'destructive';
        case 'not_delivered':
            return 'secondary';
        case 'pending':
        case 'submitted':
            return 'outline';
        default:
            return 'default';
    }
}

export type QrStatus =
    'none' | 'pending' | 'approved' | 'denied' | 'redeemed' | 'expired';

export type QrRequester = {
    device: string;
    ip: string | null;
    network: string | null;
    requested_at: string | null;
};

export type QrStatusPayload = {
    status: QrStatus;
    claimed: boolean;
    expires_in: number;
    request?: QrRequester;
    options?: number[];
};

export type QrIssued = {
    qr: string;
    number?: number;
    token?: string;
    short_code: string;
    expires_in: number;
};

export type QrFailure = {
    message?: string;
    reason?: string;
    reauthenticate?: string | null;
};

export interface BankFormat {
    id: number;
    team_id: number;
    name: string;
    start_row: number;
    date_column: string;
    description_column: string;
    amount_column: string;
    reference_column: string | null;
    type_column: string | null;
    color?: string;
    created_at: string;
    updated_at: string;
}

export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    current_team_id: number | null;
    current_team?: {
        id: number;
        user_id: number;
        name: string;
    };
    // Owner del team actual o miembro con rol 'admin' (shared prop de
    // HandleInertiaRequests); el sidebar lo usa para los módulos owner/admin.
    manages_team?: boolean;
    teams?: Array<{
        id: number;
        user_id: number;
        name: string;
    }>;
    all_teams?: Array<{
        id: number;
        user_id: number;
        name: string;
        personal_team?: boolean;
    }>;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
    filters: {
        month: number;
        year: number;
    };
    available_years: number[];
    flash: {
        success?: string;
        error?: string;
        warning?: string;
        toasts?: Array<{ type: 'success' | 'error' | 'warning', message: string }>;
    };
};

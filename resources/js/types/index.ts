import type { LucideIcon } from 'lucide-vue-next';

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    csrf_token: string;
    flash: {
        success: string | null;
        error: string | null;
    };
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export type BreadcrumbItemType = BreadcrumbItem;

export interface DeviceSummary {
    id: number;
    name: string;
    product_id: number;
}

export interface ActivitySummary {
    id: number;
    mywhoosh_id: string;
    title: string;
    start_at: string | null;
    distance_km: number | null;
    duration: string | null;
    watt: number | null;
    heartrate: number | null;
    watt_per_kg: number | null;
    sport_type: string | null;
    route_name: string | null;
    status: 'pending' | 'synced' | 'failed';
    error_message: string | null;
    synced_at: string | null;
    device: string | null;
    has_file: boolean;
}

export interface ActivitiesPage {
    data: ActivitySummary[];
    has_more: boolean;
    next_page: number | null;
    prev_page: number | null;
}

export interface ConnectionInfo {
    email: string;
    connected_at: string | null;
    remembered: boolean;
    [key: string]: unknown;
}

export interface SyncResult {
    id: number;
    status: 'pending' | 'synced' | 'failed';
    message: string;
    log: string[];
    error: string | null;
    synced_at: string | null;
    device: string;
}

<?php

declare(strict_types=1);

namespace App\Models\Enums;

/** What an `audit_sensitive` row records (DATABASE-DESIGN §3.10, PRD P-03). */
enum AuditAction: string
{
    case PriceChanged = 'price_changed';
    case CostChanged = 'cost_changed';
    case RefundCreated = 'refund_created';
    case RefundApproved = 'refund_approved';
    case RoleAssigned = 'role_assigned';
    case RoleRevoked = 'role_revoked';
    case RolePermissionsChanged = 'role_permissions_changed';
    case CredentialChanged = 'credential_changed';
    /** A delivery rider's name / phone recorded on a shipment (masked phone in the row, rule 20). */
    case ShipmentRiderRecorded = 'shipment_rider_recorded';
    /** A failed scheduled report email sent again by staff (P9) — financial data leaving the admin. */
    case ScheduledReportResent = 'scheduled_report_resent';
    /** Owner capital put in or a drawing taken out (P10) — money moving between the owner and the business. */
    case CapitalEntryRecorded = 'capital_entry_recorded';
    /** An owner-capital entry reversed by a mirror entry (P10); the reason is in the payload. */
    case CapitalEntryReversed = 'capital_entry_reversed';
    /** A capital holder (owner / partner) added or changed (P10). */
    case CapitalHolderSaved = 'capital_holder_saved';

    /** The activity-log areas (Figma B · 39 "Area" filter), in display order. */
    public const array AREAS = ['catalog', 'refunds', 'staff', 'integrations', 'fulfilment', 'reports', 'finance'];

    /**
     * Which part of the admin a row belongs to. A credential change is a staff matter when
     * its subject is a staff account (password reset, two-factor) and an integration
     * otherwise (courier, messaging, tracking credentials).
     */
    public function area(bool $staffSubject = false): string
    {
        return match ($this) {
            self::PriceChanged, self::CostChanged => 'catalog',
            self::RefundCreated, self::RefundApproved => 'refunds',
            self::RoleAssigned, self::RoleRevoked, self::RolePermissionsChanged => 'staff',
            self::CredentialChanged => $staffSubject ? 'staff' : 'integrations',
            self::ShipmentRiderRecorded => 'fulfilment',
            self::ScheduledReportResent => 'reports',
            self::CapitalEntryRecorded, self::CapitalEntryReversed, self::CapitalHolderSaved => 'finance',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $a): string => $a->value, self::cases());
    }
}

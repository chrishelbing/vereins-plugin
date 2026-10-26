<?php

namespace VereinsPlugin\Admin;

/**
 * Gemeinsames, minimales CSS für die Plugin-eigenen wp-admin-Seiten.
 */
class AdminStyles {

    public static function print_inline(): void {
        ?>
        <style>
        .vp-admin-wrap .vp-table { border-collapse: collapse; width: 100%; background: #fff; margin-bottom: 24px; }
        .vp-admin-wrap .vp-table th, .vp-admin-wrap .vp-table td { padding: 10px 12px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: middle; }
        .vp-admin-wrap .vp-table th { background: #f9fafb; font-weight: 600; }
        .vp-admin-wrap .vp-swatch { display: inline-block; width: 14px; height: 14px; border-radius: 4px; vertical-align: middle; margin-right: 6px; }
        .vp-admin-wrap .vp-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: .75rem; font-weight: 600; }
        .vp-admin-wrap .vp-badge--active  { background: #d1fae5; color: #065f46; }
        .vp-admin-wrap .vp-badge--pending { background: #fef3c7; color: #92400e; }
        .vp-admin-wrap .vp-badge--inactive{ background: #fee2e2; color: #991b1b; }
        .vp-admin-wrap .vp-badge--draft   { background: #e5e7eb; color: #374151; }
        .vp-admin-wrap .vp-form-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 6px; padding: 20px; max-width: 640px; margin-bottom: 24px; }
        .vp-admin-wrap .vp-form-card h2 { margin-top: 0; }
        /* Karten, die eine Tabelle enthalten: Die 640px sind für Formulare
           gedacht – eine Tabelle darin bekäme sonst weniger Platz als die
           Tabelle darunter und bräche ihre Aktionsknöpfe unnötig um. */
        .vp-admin-wrap .vp-form-card--wide { max-width: none; }
        .vp-admin-wrap .vp-form-card--wide .vp-table { margin-bottom: 0; }
        .vp-admin-wrap .vp-field { margin-bottom: 14px; }
        .vp-admin-wrap .vp-field label { display: block; font-weight: 600; margin-bottom: 4px; }
        .vp-admin-wrap .vp-field input[type=text], .vp-admin-wrap .vp-field input[type=email], .vp-admin-wrap .vp-field input[type=tel], .vp-admin-wrap .vp-field input[type=number], .vp-admin-wrap .vp-field input[type=datetime-local], .vp-admin-wrap .vp-field input[type=date], .vp-admin-wrap .vp-field select, .vp-admin-wrap .vp-field textarea { width: 100%; max-width: 420px; padding: 6px 8px; }
        .vp-admin-wrap .vp-row { display: flex; gap: 16px; }
        .vp-admin-wrap .vp-row .vp-field { flex: 1; }
        .vp-admin-wrap .vp-actions form { display: inline-block; margin-right: 6px; }
        .vp-admin-wrap .vp-tabs { margin: 12px 0 16px; }
        .vp-admin-wrap .vp-tabs a { margin-right: 4px; }
        /* Felder, die von einer Checkbox abhängen */
        .vp-admin-wrap .vp-dependent { margin-left: 24px; padding-left: 12px; border-left: 2px solid #e5e7eb; transition: opacity .15s; }
        .vp-admin-wrap .vp-dependent.is-disabled { opacity: .45; }
        .vp-admin-wrap .vp-dependent.is-disabled select { background: #f3f4f6; cursor: not-allowed; }
                .vp-admin-wrap .vp-roles { margin-top: 6px; }
        .vp-admin-wrap .vp-roles > summary { cursor: pointer; color: #2271b1; font-size: 12px; }
        .vp-admin-wrap .vp-roles__group { display: block; margin: 8px 0 3px; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #646970; }
        .vp-admin-wrap .vp-roles__item { display: block; margin-bottom: 2px; font-size: 13px; }
        .vp-admin-wrap .vp-roles form { padding: 8px 10px; margin-top: 6px; border: 1px solid #dcdcde; border-radius: 6px; background: #fff; }
</style>
        <?php

        // Dialoge gehören zum Grundgerüst jeder Seite – hier mit ausgeben,
        // damit keine Seite sie vergessen kann.
        AdminModal::print_assets();
    }
}

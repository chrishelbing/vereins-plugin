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
        .vp-admin-wrap #vp-rsvp-dependent.is-disabled { opacity: .45; }
        /* „?“ mit Tooltip neben „Beschreibung“ (EventForm::format_hint). */
        .vp-admin-wrap .vp-hint { position: relative; display: inline-flex; align-items: center; justify-content: center; width: 16px; height: 16px; margin-left: 4px; border-radius: 999px; background: #e5e7eb; color: #374151; font-size: 11px; font-weight: 700; line-height: 1; cursor: help; vertical-align: middle; }
        .vp-admin-wrap .vp-hint:focus { outline: 2px solid #2563eb; outline-offset: 1px; }
        .vp-admin-wrap .vp-hint__box { display: none; position: absolute; z-index: 100; left: -10px; bottom: calc(100% + 8px); width: 260px; max-width: calc(100vw - 40px); padding: 10px 12px; border-radius: 8px; background: #111827; color: #f9fafb; font-size: 12px; font-weight: 400; line-height: 1.45; text-align: left; box-shadow: 0 6px 20px rgba(0,0,0,.2); white-space: normal; }
        .vp-admin-wrap .vp-hint__box::after { content: ""; position: absolute; top: 100%; left: 12px; border: 6px solid transparent; border-top-color: #111827; }
        .vp-admin-wrap .vp-hint:hover .vp-hint__box, .vp-admin-wrap .vp-hint:focus .vp-hint__box, .vp-admin-wrap .vp-hint:focus-within .vp-hint__box { display: block; }
        .vp-admin-wrap .vp-hint__box strong { display: block; margin-bottom: 6px; font-size: 12px; }
        .vp-admin-wrap .vp-hint__row { display: block; margin-top: 6px; }
        .vp-admin-wrap .vp-hint__box code { display: inline-block; margin-top: 2px; padding: 1px 5px; border-radius: 4px; background: rgba(255,255,255,.12); color: #f9fafb; font-size: 11px; word-break: break-all; }
        .vp-admin-wrap .vp-hint__note { color: #d1d5db; }
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

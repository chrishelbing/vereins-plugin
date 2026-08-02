<?php

namespace VereinsPlugin\Support;

/**
 * Riegelt das wp-admin-Backend ab: dort haben ausschließlich Administratoren
 * etwas zu suchen. Mitglieder und Orgateam arbeiten komplett im Frontend.
 *
 * Greift an drei Stellen:
 *   1. Direkte Aufrufe von /wp-admin/  → Weiterleitung auf die Startseite
 *   2. Adminbar im Frontend            → ausgeblendet
 *   3. Profil-Weiterleitung nach Login  → Startseite statt Dashboard
 */
class BackendGuard {

    public function register(): void {
        add_action( 'admin_init',              [ $this, 'block_backend_access' ] );
        add_filter( 'show_admin_bar',          [ $this, 'hide_admin_bar' ] );
        add_filter( 'login_redirect',          [ $this, 'redirect_after_login' ], 10, 3 );
    }

    /**
     * Reine Vereinsnutzer beim Aufruf von wp-admin auf die Startseite schicken.
     *
     * Ausdrücklich NICHT betroffen ist, wer daneben eine Rolle der Seite hat.
     * Ein Redakteur, der im Verein mithilft, behält sein Backend – das Plugin
     * nimmt nichts weg, was die Seite schon vergeben hatte.
     *
     * AJAX-Requests bleiben erlaubt, sonst brechen Frontend-Funktionen,
     * die über admin-ajax.php bzw. admin-post.php laufen.
     */
    public function block_backend_access(): void {
        if ( wp_doing_ajax() || ! is_user_logged_in() ) {
            return;
        }

        // admin-post.php wird von unseren eigenen Frontend-Formularen genutzt.
        $script = basename( $_SERVER['PHP_SELF'] ?? '' );
        if ( in_array( $script, [ 'admin-post.php', 'admin-ajax.php' ], true ) ) {
            return;
        }

        if ( Capabilities::is_admin_user() || ! Capabilities::is_club_only() ) {
            return;
        }

        wp_safe_redirect( home_url( '/' ) );
        exit;
    }

    public function hide_admin_bar( $show ) {
        // Gleiche Regel wie beim Backend: Wer eine Rolle der Seite hat,
        // behält seine Werkzeugleiste.
        return ( Capabilities::is_admin_user() || ! Capabilities::is_club_only() ) ? $show : false;
    }

    /**
     * @param string $redirect_to
     * @param string $requested
     * @param mixed  $user
     */
    public function redirect_after_login( $redirect_to, $requested, $user ) {
        if ( ! $user instanceof \WP_User || ! $user->exists() ) {
            return $redirect_to;
        }

        if ( Capabilities::is_admin_user( $user->ID ) || ! Capabilities::is_club_only( $user->ID ) ) {
            return $redirect_to;
        }

        // Ein ausdrücklich gewünschtes Frontend-Ziel bleibt erhalten.
        if ( $redirect_to && ! str_contains( $redirect_to, 'wp-admin' ) ) {
            return $redirect_to;
        }

        return home_url( '/' );
    }
}

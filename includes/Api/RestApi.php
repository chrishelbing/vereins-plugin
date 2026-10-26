<?php

namespace VereinsPlugin\Api;

use VereinsPlugin\Auth\AuthController;
use VereinsPlugin\Events\EventTypeController;
use VereinsPlugin\Events\EventController;
use VereinsPlugin\Photos\PhotoController;

/**
 * Zentraler REST-API-Registrar.
 * Neue Controller werden hier eingehängt.
 */
class RestApi {

    private const NAMESPACE = 'vereins-plugin/v1';

    public function register_routes(): void {
        ( new AuthController()      )->register_routes( self::NAMESPACE );
        ( new MeController()        )->register_routes( self::NAMESPACE );
        ( new EventTypeController() )->register_routes( self::NAMESPACE );
        ( new EventController()     )->register_routes( self::NAMESPACE );
        ( new PhotoController()     )->register_routes( self::NAMESPACE );

        // Weitere Controller:
        // ( new BringlistController()   )->register_routes( self::NAMESPACE );
        // ( new AttendanceController()  )->register_routes( self::NAMESPACE );
        // ( new ChannelController()     )->register_routes( self::NAMESPACE );
    }
}

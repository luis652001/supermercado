<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/bootstrap.php';
require_once __DIR__ . '/../app/AuthService.php';

Http::run(function (): void {
    (new Router())
        ->add('GET', '/health', fn () => Http::json(200, ['status' => 'ok', 'servicio' => 'auth-service']))
        ->add('POST', '/registro', [AuthService::class, 'registro'])
        ->add('POST', '/login', [AuthService::class, 'login'])
        ->add('POST', '/logout', [AuthService::class, 'logout'])
        ->add('GET', '/me', [AuthService::class, 'perfil'])
        ->add('PUT', '/me', [AuthService::class, 'actualizarPerfil'])
        ->add('GET', '/admin/acceso', [AuthService::class, 'accesoAdmin'])
        ->add('GET', '/admin/usuarios', [AuthService::class, 'adminListarUsuarios'])
        ->add('POST', '/admin/usuarios', [AuthService::class, 'adminCrearUsuario'])
        ->add('PUT', '/admin/usuarios/(\d+)', [AuthService::class, 'adminActualizarUsuario'])
        ->add('PUT', '/admin/usuarios/(\d+)/suspender', [AuthService::class, 'adminSuspenderUsuario'])
        ->add('DELETE', '/admin/usuarios/(\d+)', [AuthService::class, 'adminEliminarUsuario'])
        ->add('GET', '/admin/roles', [AuthService::class, 'adminRolesPermisos'])
        ->dispatch();
});

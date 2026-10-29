<?php
declare(strict_types=1);

/**
 * Roles y permisos del sistema (RBAC simple).
 * El registro público siempre crea "cliente"; solo un admin asigna otros roles.
 */
final class Roles
{
    public const CLIENTE = 'cliente';
    public const ADMIN = 'admin';
    public const OPERADOR = 'operador';
    public const CONTADOR = 'contador';

    public const TODOS = [self::CLIENTE, self::ADMIN, self::OPERADOR, self::CONTADOR];
    public const STAFF = [self::ADMIN, self::OPERADOR, self::CONTADOR];

    /** Permisos disponibles en el panel. */
    public const PERMISOS = [
        'panel.acceso' => 'Entrar al panel de administración',
        'usuarios.gestionar' => 'Crear, editar, suspender y eliminar usuarios',
        'roles.ver' => 'Ver matriz de roles y permisos',
        'catalogo.gestionar' => 'Gestionar productos y precios',
        'pedidos.gestionar' => 'Ver pedidos y cambiar estados',
        'clientes.exportar' => 'Exportar clientes CSV',
        'pagos.ver' => 'Ver todos los pagos',
        'bitacora.ver' => 'Ver bitácora de seguridad',
        'dashboard.ver' => 'Ver resumen y gráficos',
    ];

    /** @var array<string, list<string>> */
    private const MATRIZ = [
        self::ADMIN => [
            'panel.acceso', 'usuarios.gestionar', 'roles.ver', 'catalogo.gestionar',
            'pedidos.gestionar', 'clientes.exportar', 'pagos.ver', 'bitacora.ver', 'dashboard.ver',
        ],
        self::OPERADOR => [
            'panel.acceso', 'roles.ver', 'catalogo.gestionar', 'pedidos.gestionar', 'dashboard.ver',
        ],
        self::CONTADOR => [
            'panel.acceso', 'roles.ver', 'pagos.ver', 'clientes.exportar', 'dashboard.ver',
        ],
        self::CLIENTE => [],
    ];

    public static function esValido(string $rol): bool
    {
        return in_array($rol, self::TODOS, true);
    }

    public static function esStaff(string $rol): bool
    {
        return in_array($rol, self::STAFF, true);
    }

    /** @return list<string> */
    public static function permisosDe(string $rol): array
    {
        return self::MATRIZ[$rol] ?? [];
    }

    public static function puede(string $rol, string $permiso): bool
    {
        return in_array($permiso, self::permisosDe($rol), true);
    }

    /** Descripción para la UI del admin. */
    public static function catalogo(): array
    {
        $roles = [];
        foreach (self::TODOS as $rol) {
            $roles[] = [
                'rol' => $rol,
                'nombre' => match ($rol) {
                    self::ADMIN => 'Administrador',
                    self::OPERADOR => 'Operador (bodega / pedidos)',
                    self::CONTADOR => 'Contador (pagos)',
                    default => 'Cliente',
                },
                'descripcion' => match ($rol) {
                    self::ADMIN => 'Control total del sistema',
                    self::OPERADOR => 'Catálogo y estados de pedidos',
                    self::CONTADOR => 'Consulta de pagos y exportación',
                    default => 'Compra en la tienda',
                },
                'permisos' => self::permisosDe($rol),
            ];
        }
        return [
            'roles' => $roles,
            'permisos' => self::PERMISOS,
        ];
    }
}

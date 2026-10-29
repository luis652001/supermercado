<?php
declare(strict_types=1);

final class Router
{
    /** @var list<array{0: string, 1: string, 2: callable}> */
    private array $rutas = [];

    public function add(string $metodo, string $patron, callable $handler): self
    {
        $this->rutas[] = [$metodo, $patron, $handler];
        return $this;
    }

    public function dispatch(): void
    {
        $metodo = Http::method();
        $ruta = Http::path();
        $permitidos = [];

        foreach ($this->rutas as [$metodoRuta, $patron, $handler]) {
            if (!preg_match('#^' . $patron . '$#', $ruta, $coincidencias)) {
                continue;
            }
            if ($metodoRuta !== $metodo) {
                $permitidos[] = $metodoRuta;
                continue;
            }
            $handler(...array_slice($coincidencias, 1));
            return;
        }

        if ($permitidos) {
            header('Allow: ' . implode(', ', array_unique($permitidos)));
            Http::json(405, ['error' => 'Método no permitido']);
        }
        Http::json(404, ['error' => 'Ruta no encontrada']);
    }
}

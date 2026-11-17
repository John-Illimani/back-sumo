<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RfidController extends Controller
{
    // 1. Registra la lectura de la tarjeta en la BD y devuelve los datos + foto para el modal
    public function registrarLectura(Request $request)
    {
        $request->validate([
            'uuid' => 'required|string|max:100'
        ]);

        $uuid = trim($request->input('uuid'));

        // Busca al usuario por UUID (sin importar mayúsculas o minúsculas)
        $usuario = Usuario::whereRaw('UPPER(uuid) = ?', [strtoupper($uuid)])->first();

        if (!$usuario) {
            return response()->json([
                'status' => 'error',
                'mensaje' => 'Tarjeta RFID no registrada en el sistema.'
            ], 404);
        }

        // Inserta el ingreso en la tabla registros_rfid
        $registroId = DB::table('registros_rfid')->insertGetId([
            'usuario_id' => $usuario->usuario_id,
            'uuid_leido' => strtoupper($uuid),
            'fecha_hora' => now(),
            'tipo_evento' => 'Ingreso'
        ], 'registro_id');

        return response()->json([
            'status' => 'success',
            'mensaje' => 'Lectura RFID registrada correctamente',
            'registro_id' => $registroId,
            'usuario' => [
                'usuario_id' => $usuario->usuario_id,
                'nombre_completo' => trim($usuario->nombre . ' ' . $usuario->apellido),
                'ci' => $usuario->ci,
                'uuid' => $usuario->uuid,
                'rol' => $usuario->rol,
                'foto_base64' => $usuario->foto_base64
            ]
        ], 201);
    }

    // 2. Devuelve los últimos 20 ingresos para mostrarlos en la tabla del Dashboard
    public function historialReciente()
    {
        $registros = DB::table('registros_rfid as r')
            ->join('usuarios as u', 'r.usuario_id', '=', 'u.usuario_id')
            ->select(
                'r.registro_id',
                'r.uuid_leido',
                'r.fecha_hora',
                'r.tipo_evento',
                'u.usuario_id',
                'u.nombre',
                'u.apellido',
                'u.ci',
                'u.rol'
            )
            ->orderBy('r.fecha_hora', 'desc')
            ->limit(20)
            ->get();

        // Obtiene la foto en Base64 de cada usuario involucrado
        $usuariosIds = $registros->pluck('usuario_id')->unique();
        $usuariosConFoto = Usuario::whereIn('usuario_id', $usuariosIds)->get()->keyBy('usuario_id');

        $resultado = $registros->map(function ($item) use ($usuariosConFoto) {
            $user = $usuariosConFoto->get($item->usuario_id);
            return [
                'registro_id' => $item->registro_id,
                'nombre_completo' => trim($item->nombre . ' ' . $item->apellido),
                'ci' => $item->ci,
                'rol' => $item->rol,
                'uuid' => $item->uuid_leido,
                'fecha_hora' => $item->fecha_hora,
                'tipo_evento' => $item->tipo_evento,
                'foto_base64' => $user ? $user->foto_base64 : null,
            ];
        });

        return response()->json($resultado);
    }
}
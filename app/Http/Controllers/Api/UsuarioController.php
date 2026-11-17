<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Models\Administrador;
use App\Models\Secretaria;
use App\Models\Postulante;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UsuarioController extends Controller
{
    public function index()
    {
        return response()->json(
            Usuario::with(['administrador', 'secretaria', 'postulante'])->get()
        );
    }

    public function store(Request $request)
    {
        // 1. Validar únicamente los datos reales requeridos
        $validated = $request->validate([
            'rol' => 'required|in:administrador,secretaria,postulante',
            'nombre' => 'required|string|max:100',
            'apellido' => 'required|string|max:100',
            'ci' => 'required|string|max:20|unique:usuarios,ci',
            'celular' => 'nullable|string|max:20',
            'foto' => 'nullable', // archivo (multipart) o imagen en base64 (JSON)
            // Campos opcionales específicos para tablas hijas si aplican
            'ventanilla_asignada' => 'nullable|string|max:50',
            'turno' => 'nullable|string|max:50',
            'direccion' => 'nullable|string',
            'fecha_nacimiento' => 'nullable|date',
            'uuid' => 'nullable|string|max:50|unique:usuarios,uuid',
        ]);

        // 2. Extraer primer nombre y primer apellido limpios (sin espacios ni acentos)
        $primerNombre = Str::slug(explode(' ', trim($validated['nombre']))[0], '');
        $primerApellido = Str::slug(explode(' ', trim($validated['apellido']))[0], '');
        $ciLimpio = preg_replace('/[^0-9]/', '', $validated['ci']);

        // 3. Generar Username automático: @nombre_apellido
        $baseUsername = "@" . strtolower($primerNombre) . "_" . strtolower($primerApellido);
        $username = $baseUsername;
        $contador = 1;

        while (Usuario::where('username', $username)->exists()) {
            $username = $baseUsername . $contador;
            $contador++;
        }

        // 4. Generar Password inicial automático: nombre + CI + *
        $plainPassword = strtolower($primerNombre) . $ciLimpio . "*";
        $hashedPassword = hash('sha256', $plainPassword);

        $uuid = $request->input('uuid', null);

        // 5. Procesar la foto (redimensiona y convierte a formato bytea)
        $fotoBinaria = $this->procesarFoto($request->file('foto') ?? $request->input('foto'));

        // 6. Transacción atómica: Inserción en usuarios + tabla hija correspondiente
        $usuarioCreado = DB::transaction(function () use ($validated, $username, $hashedPassword, $uuid, $fotoBinaria, $request) {
            $usuario = Usuario::create([
                'username' => $username,
                'password' => $hashedPassword,
                'rol' => $validated['rol'],
                'nombre' => $validated['nombre'],
                'apellido' => $validated['apellido'],
                'ci' => $validated['ci'],
                'celular' => $validated['celular'] ?? null,
                'foto' => $fotoBinaria,
                'uuid' => $uuid,
            ]);

            // Inserción en tabla hija según el rol
            if ($usuario->rol === 'administrador') {
                Administrador::create([
                    'usuario_id' => $usuario->usuario_id,
                ]);
            } elseif ($usuario->rol === 'secretaria') {
                Secretaria::create([
                    'usuario_id' => $usuario->usuario_id,
                    'ventanilla_asignada' => $request->ventanilla_asignada ?? 'Ventanilla 1',
                    'turno' => $request->turno ?? 'Continuo',
                ]);
            } elseif ($usuario->rol === 'postulante') {
                Postulante::create([
                    'usuario_id' => $usuario->usuario_id,
                    'codigo_postulante' => 'POST-' . date('Y') . '-' . rand(1000, 9999),
                    'direccion' => $request->direccion ?? null,
                    'fecha_nacimiento' => $request->fecha_nacimiento ?? null,
                ]);
            }

            return $usuario;
        });

        return response()->json([
            'status' => 'success',
            'mensaje' => 'Usuario y perfil creados exitosamente',
            'usuario' => $usuarioCreado->load(['administrador', 'secretaria', 'postulante']),
            'credenciales_generadas' => [
                'username' => $username,
                'password_inicial' => $plainPassword
            ]
        ], 201);
    }

    public function show(int $id)
    {
        $usuario = Usuario::with(['secretaria', 'administrador', 'postulante'])->findOrFail($id);
        return response()->json($usuario);
    }

    public function update(Request $request, int $id)
    {
        $usuario = Usuario::findOrFail($id);

        $validated = $request->validate([
            'username' => 'sometimes|string|max:60|unique:usuarios,username,' . $id . ',usuario_id',
            'password' => 'nullable|string|min:6',
            'rol' => 'sometimes|in:administrador,secretaria,postulante',
            'nombre' => 'sometimes|string|max:100',
            'apellido' => 'sometimes|string|max:100',
            'ci' => 'sometimes|string|max:20|unique:usuarios,ci,' . $id . ',usuario_id',
            'celular' => 'nullable|string|max:20',
            'uuid' => 'nullable|string|max:50|unique:usuarios,uuid,' . $id . ',usuario_id',
            'foto' => 'nullable',
            'eliminar_foto' => 'nullable|boolean',
        ]);

        if (!empty($validated['password'])) {
            $validated['password'] = hash('sha256', $validated['password']);
        } else {
            unset($validated['password']);
        }

        // Foto: solo se toca si llega una nueva o si piden eliminarla
        unset($validated['foto'], $validated['eliminar_foto']);

        if ($request->boolean('eliminar_foto')) {
            $validated['foto'] = null;
        } elseif ($request->hasFile('foto') || $request->filled('foto')) {
            $validated['foto'] = $this->procesarFoto($request->file('foto') ?? $request->input('foto'));
        }

        $usuario->update($validated);

        return response()->json([
            'status' => 'success',
            'mensaje' => 'Usuario actualizado exitosamente',
            'usuario' => $usuario->load(['administrador', 'secretaria', 'postulante'])
        ]);
    }

    public function destroy(int $id)
    {
        $usuario = Usuario::findOrFail($id);
        $usuario->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Usuario eliminado correctamente'
        ]);
    }

    /**
     * Recibe una foto (archivo subido o texto base64 / data URL), verifica que sea
     * una imagen real (JPG, PNG o WEBP) y la devuelve en formato hexadecimal listo
     * para guardar en una columna bytea de PostgreSQL.
     *
     * No requiere la extensión GD de PHP: el frontend ya reduce la foto a 400 px
     * y la comprime a JPEG antes de enviarla.
     */
    private function procesarFoto($foto): ?string
    {
        if (empty($foto)) {
            return null;
        }

        // 1. Obtener los bytes de la imagen
        if ($foto instanceof UploadedFile) {
            $binario = file_get_contents($foto->getRealPath());
        } elseif (is_string($foto)) {
            $limpio = preg_replace('#^data:image/[a-zA-Z0-9.+-]+;base64,#', '', $foto);
            $binario = base64_decode($limpio, true);
        } else {
            $binario = false;
        }

        if ($binario === false || $binario === '') {
            throw ValidationException::withMessages([
                'foto' => 'La foto no es válida.'
            ]);
        }

        // 2. Limitar el tamaño (la foto ya llega comprimida, normalmente 20 a 60 KB)
        if (strlen($binario) > 2 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'foto' => 'La foto supera los 2 MB. Elija una imagen más liviana.'
            ]);
        }

        // 3. Verificar que realmente sea una imagen (no depende de GD)
        $info = @getimagesizefromstring($binario);
        $permitidos = ['image/jpeg', 'image/png', 'image/webp'];

        if ($info === false || !in_array($info['mime'], $permitidos, true)) {
            throw ValidationException::withMessages([
                'foto' => 'El archivo no es una imagen válida (use JPG, PNG o WEBP).'
            ]);
        }

        // 4. Formato hexadecimal que PostgreSQL entiende como bytea
        return '\\x' . bin2hex($binario);
    }
}
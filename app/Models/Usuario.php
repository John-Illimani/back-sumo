<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class Usuario extends Authenticatable implements JWTSubject
{
    protected $table = 'usuarios';
    protected $primaryKey = 'usuario_id';
    public $timestamps = false;

    protected $fillable = [
        'username', 'password', 'rol', 'nombre', 'apellido', 
        'ci', 'celular', 'foto', 'uuid'
    ];

    // Ocultamos 'foto' (binario crudo) para evitar errores de codificación JSON
    protected $hidden = ['password', 'foto'];

    // Incluimos automáticamente la foto convertida a Base64 en las respuestas JSON
    protected $appends = ['foto_base64'];

    // Convierte el campo bytea de PostgreSQL a Data URL Base64
    public function getFotoBase64Attribute()
    {
        $foto = $this->attributes['foto'] ?? null;

        if (is_resource($foto)) {
            // Foto leída desde PostgreSQL (llega como stream)
            rewind($foto);
            $foto = stream_get_contents($foto);
        } elseif (is_string($foto) && strpos($foto, '\\x') === 0) {
            // Foto recién guardada en esta misma petición (llega en hexadecimal)
            $foto = hex2bin(substr($foto, 2));
        }

        if (empty($foto)) {
            return null;
        }

        return 'data:image/jpeg;base64,' . base64_encode($foto);
    }

    // Identificador único para el subject del JWT
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    // Claims personalizados en el payload del JWT
    public function getJWTCustomClaims()
    {
        return [
            'rol' => $this->rol,
            'ci' => $this->ci,
            'uuid' => $this->uuid,
            'nombre_completo' => $this->nombre . ' ' . $this->apellido
        ];
    }

    public function secretaria()
    {
        return $this->hasOne(Secretaria::class, 'usuario_id', 'usuario_id');
    }

    public function administrador()
    {
        return $this->hasOne(Administrador::class, 'usuario_id', 'usuario_id');
    }

    public function postulante()
    {
        return $this->hasOne(Postulante::class, 'usuario_id', 'usuario_id');
    }
}
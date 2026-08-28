<?php

namespace Esolutions\XmlPeru;

/**
 * Lo que devuelve dar de alta una empresa: sus datos, su token de firma y sus
 * credenciales de login.
 *
 * Es un objeto y no un array por una razón concreta: el token en claro solo se
 * muestra en esa respuesta y nunca más. Que el alta devuelva directamente
 * `cpe()` —un cliente ya autenticado— quita el paso manual donde ese token se
 * pierde.
 */
class Empresa
{
    /** @var array */
    private $datos;

    public function __construct(array $datos)
    {
        $this->datos = $datos;
    }

    /** @return string|null */
    public function ruc()
    {
        return isset($this->datos['empresa']['ruc'])
            ? $this->datos['empresa']['ruc']
            : (isset($this->datos['empresa']['number']) ? $this->datos['empresa']['number'] : null);
    }

    /** Datos de la empresa. @return array */
    public function datos()
    {
        return isset($this->datos['empresa']) ? $this->datos['empresa'] : array();
    }

    /**
     * Token de firma en claro. Guárdalo: no se vuelve a mostrar.
     *
     * @return string|null
     */
    public function token()
    {
        return isset($this->datos['token']['plain_text_token'])
            ? $this->datos['token']['plain_text_token']
            : null;
    }

    /**
     * Usuario y contraseña, para quien prefiera el login estilo QPSE.
     *
     * @return array
     */
    public function credenciales()
    {
        return isset($this->datos['credenciales']) ? $this->datos['credenciales'] : array();
    }

    /** Cliente de firma listo para emitir. @return Cpe */
    public function cpe()
    {
        return new Cpe($this->token());
    }

    /** Respuesta completa del alta. @return array */
    public function respuesta()
    {
        return $this->datos;
    }
}
